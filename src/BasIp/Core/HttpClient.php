<?php

declare(strict_types=1);

namespace BasIp\Core;

use Throwable;

/**
 * cURL wrapper used for every outbound call: the BAS-IP panel, GitHub
 * (updates), Telegram, SMS gateways and webhooks.
 *
 * Features that matter in production and are missing from `file_get_contents`:
 *  * connect + total timeouts, retry with exponential back-off and jitter
 *  * TLS verification with an optional custom CA bundle (panels use
 *    self-signed certificates on the LAN — `verify_tls: false` is per-device)
 *  * redirect policy, proxy support, HTTP/2 where available
 *  * a simple circuit breaker so a dead panel does not stall every request
 *  * structured logging of each attempt (URL, status, duration, attempt #)
 */
final class HttpClient
{
    private const DEFAULT_TIMEOUT = 10;

    /** @var array<string, int> circuit breaker state: host => open-until timestamp */
    private static array $circuits = [];

    public function __construct(
        private readonly Config $config,
        private readonly ?Logger $logger = null,
        private readonly string $channel = 'device',
    ) {
    }

    /** @param array<string, string|int> $query */
    public function get(string $url, array $query = [], array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options + ['query' => $query]);
    }

    public function post(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options + ['body' => $body]);
    }

    public function put(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options + ['body' => $body]);
    }

    public function patch(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options + ['body' => $body]);
    }

    public function delete(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options + ['body' => $body]);
    }

    /** @param array<string, mixed> $body */
    public function postJson(string $url, array $body, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options + ['json' => $body]);
    }

    /** @param array<string, mixed> $query */
    public function getJson(string $url, array $query = [], array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options + ['query' => $query, 'accept' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $options
     *   query, json, body, form, multipart, headers, accept, bearer, basic,
     *   timeout, connect_timeout, retries, verify_tls, ca_bundle, proxy,
     *   follow, max_redirects, raw_response (binary), user_agent, throw
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $method  = strtoupper($method);
        $timeout = (int) ($options['timeout'] ?? $this->config->int('device.timeout', self::DEFAULT_TIMEOUT));
        $connect = (int) ($options['connect_timeout'] ?? $this->config->int('device.connect_timeout', 4));
        $retries = max(0, (int) ($options['retries'] ?? $this->config->int('device.retries', 2)));
        $delayMs = max(0, (int) ($options['retry_delay_ms'] ?? $this->config->int('device.retry_delay_ms', 250)));
        $throw   = (bool) ($options['throw'] ?? false);

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        if ($this->circuitOpen($host)) {
            $response = new HttpResponse(503, '', [], $url, 0.0, 0, 'circuit-open: ' . $host);
            $this->log('circuit-open', $method, $url, $response);
            if ($throw) {
                throw new HttpException('Circuit open for host ' . $host, 0, $url, $response);
            }

            return $response;
        }

        $attempt = 0;
        $startedAt = microtime(true);
        $last = null;

        while ($attempt <= $retries) {
            $attempt++;
            try {
                $response = $this->execute($method, $url, $options, $timeout, $connect, $attempt);
                $last = $response;

                $retryable = $response->error !== null
                    || $response->status === 0
                    || $response->status === 408
                    || $response->status === 425
                    || $response->status === 429
                    || $response->status >= 500;

                if (!$retryable) {
                    $this->circuitClose($host);
                    $this->log('ok', $method, $url, $response);

                    return $response;
                }

                if ($attempt > $retries) {
                    $this->circuitTrip($host, $options);
                    $this->log('failed', $method, $url, $response);
                    break;
                }

                $sleep = $this->backoff($attempt, $delayMs, (int) ($response->header('retry-after') ?? 0));
                $this->logger?->debug('Retrying HTTP request', [
                    'url' => $url, 'attempt' => $attempt, 'sleep_ms' => $sleep, 'status' => $response->status,
                ] + ['channel' => $this->channel]);
                usleep($sleep * 1000);
            } catch (Throwable $e) {
                $last = new HttpResponse(0, '', [], $url, (microtime(true) - $startedAt) * 1000, $attempt, $e->getMessage());
                if ($attempt > $retries) {
                    $this->circuitTrip($host, $options);
                    $this->logger?->error('HTTP request failed', ['url' => $url, 'error' => $e->getMessage(), 'attempts' => $attempt] + ['channel' => $this->channel]);
                    break;
                }
                usleep($this->backoff($attempt, $delayMs, 0) * 1000);
            }
        }

        $response = $last ?? new HttpResponse(0, '', [], $url, (microtime(true) - $startedAt) * 1000, $attempt, 'no response');
        if ($throw && $response->failed()) {
            throw new HttpException(
                sprintf('HTTP %s %s failed: %s', $method, $url, $response->error ?? ('status ' . $response->status)),
                $response->errno,
                $url,
                $response
            );
        }

        return $response;
    }

    /**
     * Stream a binary response straight to a file (firmware, snapshots, update archives).
     */
    public function download(string $url, string $destination, array $options = []): HttpResponse
    {
        $options['raw_response'] = false;
        $options['stream_to']    = $destination;

        return $this->request('GET', $url, $options);
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @param array<string, mixed> $options */
    private function execute(string $method, string $url, array $options, int $timeout, int $connect, int $attempt): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('The cURL PHP extension is required.', 0, $url);
        }

        $query = $options['query'] ?? [];
        if (is_array($query) && $query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [];
        $accept  = $options['accept'] ?? null;
        if (is_string($accept) && $accept !== '') {
            $headers[] = 'Accept: ' . $accept;
        }
        foreach ((array) ($options['headers'] ?? []) as $name => $value) {
            if (is_int($name)) {
                $headers[] = (string) $value;
            } elseif ($value !== null && $value !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }
        if (isset($options['bearer']) && $options['bearer'] !== null && $options['bearer'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $options['bearer'];
        }
        if (isset($options['basic']) && is_array($options['basic'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode(implode(':', $options['basic']));
        }

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => max(1, $connect),
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => (bool) ($options['follow'] ?? false),
            CURLOPT_MAXREDIRS      => (int) ($options['max_redirects'] ?? 3),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_tls'] ?? $this->config->bool('device.verify_tls', true)),
            CURLOPT_SSL_VERIFYHOST => ($options['verify_tls'] ?? $this->config->bool('device.verify_tls', true)) ? 2 : 0,
            CURLOPT_USERAGENT      => (string) ($options['user_agent'] ?? $this->config->string('device.user_agent', 'BAS-IP-Gate-Manager/2.0')),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_NOSIGNAL       => 1,
        ];

        if (!empty($options['ca_bundle'])) {
            $curlOptions[CURLOPT_CAINFO] = (string) $options['ca_bundle'];
        }
        if (!empty($options['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = (string) $options['proxy'];
        }
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }

        // Body handling.
        if (isset($options['json']) && $options['json'] !== null) {
            $payload = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $curlOptions[CURLOPT_POSTFIELDS] = $payload === false ? '{}' : $payload;
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        } elseif (isset($options['multipart']) && is_array($options['multipart'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['multipart'];
        } elseif (isset($options['form']) && is_array($options['form'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($options['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        } elseif (isset($options['body']) && $options['body'] !== null) {
            $body = $options['body'];
            if (is_array($body)) {
                $body = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
                $headers[] = 'Content-Type: application/json';
                $curlOptions[CURLOPT_HTTPHEADER] = $headers;
            }
            $curlOptions[CURLOPT_POSTFIELDS] = (string) $body;
        }

        $streamTo = $options['stream_to'] ?? null;
        $fileHandle = null;
        if (is_string($streamTo) && $streamTo !== '') {
            $dir = dirname($streamTo);
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            $fileHandle = @fopen($streamTo, 'wb');
            if ($fileHandle === false) {
                throw new HttpException('Unable to open destination file: ' . $streamTo, 0, $url);
            }
            $curlOptions[CURLOPT_RETURNTRANSFER] = false;
            $curlOptions[CURLOPT_FILE] = $fileHandle;
            $curlOptions[CURLOPT_HEADER] = false;
        }

        $responseHeaders = [];
        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($ch, string $header) use (&$responseHeaders): int {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $len;
        };

        $ch = curl_init();
        if ($ch === false) {
            if ($fileHandle !== null) {
                fclose($fileHandle);
            }

            throw new HttpException('Unable to initialise cURL.', 0, $url);
        }
        curl_setopt_array($ch, $curlOptions);

        $startedAt = microtime(true);
        $raw = curl_exec($ch);
        $duration = (microtime(true) - $startedAt) * 1000;
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // curl_close() is deprecated as of PHP 8.5 (a no-op since PHP 8.0):
        // the handle is a CurlHandle object and is freed by the garbage collector.
        unset($ch);

        if ($fileHandle !== null) {
            fclose($fileHandle);
        }

        if ($errno !== 0) {
            return new HttpResponse($status, '', $responseHeaders, $url, $duration, $attempt, $error !== '' ? $error : 'cURL error ' . $errno, $errno);
        }

        $body = '';
        if (is_string($raw)) {
            $body = ($headerSize > 0 && !empty($curlOptions[CURLOPT_HEADER]))
                ? substr($raw, $headerSize)
                : $raw;
        }

        return new HttpResponse($status, $body, $responseHeaders, $url, $duration, $attempt);
    }

    private function backoff(int $attempt, int $baseMs, int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds > 0) {
            return min(30000, $retryAfterSeconds * 1000);
        }
        $exp   = $baseMs * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) max(1, $exp * 0.25));

        return (int) min(15000, $exp + $jitter);
    }

    private function circuitOpen(string $host): bool
    {
        $until = self::$circuits[$host] ?? 0;
        if ($until === 0) {
            return false;
        }
        if ($until < time()) {
            unset(self::$circuits[$host]);

            return false;      // half-open: allow a probe
        }

        return true;
    }

    /** @param array<string, mixed> $options */
    private function circuitTrip(string $host, array $options): void
    {
        if (($options['circuit_breaker'] ?? true) === false) {
            return;
        }
        $cooldown = (int) ($options['circuit_cooldown'] ?? 30);
        if ($cooldown > 0) {
            self::$circuits[$host] = time() + $cooldown;
        }
    }

    private function circuitClose(string $host): void
    {
        unset(self::$circuits[$host]);
    }

    private function log(string $event, string $method, string $url, HttpResponse $response): void
    {
        $level = $response->ok() ? 'debug' : 'warning';
        $this->logger?->{$level}('HTTP ' . $event, [
            'method'      => $method,
            'url'         => self::redactUrl($url),
            'status'      => $response->status,
            'duration_ms' => round($response->durationMs, 1),
            'attempts'    => $response->attempts,
            'error'       => $response->error,
            'channel'     => $this->channel,
        ]);
    }

    public static function redactUrl(string $url): string
    {
        return (string) preg_replace('/(password|passwd|pwd|token|api[_-]?key|secret)=([^&]+)/i', '$1=***', $url);
    }

    /** @return array<string, int> */
    public static function circuitState(): array
    {
        return self::$circuits;
    }

    public static function resetCircuits(): void
    {
        self::$circuits = [];
    }
}
