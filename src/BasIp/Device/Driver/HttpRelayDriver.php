<?php

declare(strict_types=1);

namespace BasIp\Device\Driver;

use BasIp\Core\Config;
use BasIp\Core\HttpClient;
use BasIp\Core\Logger;
use BasIp\Device\GateDriver;
use BasIp\Device\GateResult;
use BasIp\Support\Arr;
use Throwable;

/**
 * Universal HTTP relay driver.
 *
 * Anything that can be triggered by an HTTP request works here: NetPing,
 * Sonoff/eWeLink LAN mode, ESP32/Arduino with a tiny web server, a Home
 * Assistant webhook, an MQTT-HTTP bridge, a dry-contact board behind nginx…
 *
 * Configure with URL templates, e.g.:
 *
 *   relay:
 *     open_url:   'http://192.168.1.50/relay/1/on'
 *     close_url:  'http://192.168.1.50/relay/1/off'
 *     status_url: 'http://192.168.1.50/status.json'
 *     method:     GET
 *     auth:       basic            # none | basic | bearer | token-param
 *     success_when: '"state":"on"' # substring that means success
 *     status_path:  relay.state    # dot-path into the JSON status response
 */
final class HttpRelayDriver implements GateDriver
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $id = 'relay',
    ) {
        $this->options = (array) $config->section('device');
    }

    public function name(): string  { return 'http-relay'; }
    public function label(): string { return 'Универсальное HTTP-реле'; }

    public function configure(array $options): void
    {
        $this->options = array_merge($this->options, array_filter($options, static fn ($v) => $v !== null && $v !== ''));
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['open', 'close', 'status'], true);
    }

    public function open(int $channel = 1): GateResult
    {
        return $this->hit((string) $this->opt('relay.open_url', ''), $channel, 'open');
    }

    public function close(int $channel = 1): GateResult
    {
        $url = (string) $this->opt('relay.close_url', '');
        if ($url === '') {
            return GateResult::failure('URL закрытия не настроен.', 'not_configured');
        }

        return $this->hit($url, $channel, 'close');
    }

    public function status(): GateResult
    {
        $url = (string) $this->opt('relay.status_url', '');
        if ($url === '') {
            return GateResult::success('Реле настроено (проверка состояния недоступна)', ['reachable' => null]);
        }

        $started = microtime(true);
        $response = $this->http->request((string) $this->opt('relay.method', 'GET'), $url, $this->httpOptions());
        $ms = (microtime(true) - $started) * 1000;
        $data = $response->json() ?? [];

        if (!$response->ok()) {
            return GateResult::failure('Реле вернуло HTTP ' . $response->status, (string) $response->status, $data, $response->status, $ms);
        }

        $path = $this->opt('relay.status_path');
        $state = is_string($path) && $path !== '' ? Arr::get($data, $path) : null;

        return GateResult::success('Статус получен', [
            'reachable' => true, 'state' => $state, 'raw_status' => $data,
        ], $data, $ms, $response->status);
    }

    public function ping(): GateResult
    {
        $status = $this->status();

        return $status->ok
            ? GateResult::success('Реле на связи', ['driver' => $this->name()], $status->raw, $status->durationMs)
            : $status;
    }

    public function time(): GateResult
    {
        return GateResult::success('Время не поддерживается этим драйвером', ['supported' => false]);
    }

    public function snapshot(): ?string
    {
        $url = (string) $this->opt('relay.snapshot_url', '');
        if ($url === '') {
            return null;
        }
        $response = $this->http->request('GET', $url, $this->httpOptions() + ['retries' => 0, 'accept' => 'image/*']);

        return $response->ok() && str_contains((string) $response->header('content-type'), 'image') ? $response->body : null;
    }

    public function cameras(): GateResult
    {
        $url = (string) $this->opt('relay.cameras_url', '');
        if ($url === '') {
            return GateResult::success('Камеры не настроены', ['cameras' => []]);
        }
        $response = $this->http->request('GET', $url, $this->httpOptions());
        $data = $response->json() ?? [];

        return GateResult::success('Камеры получены', ['cameras' => (array) ($data['cameras'] ?? $data)], $data, $response->durationMs, $response->status);
    }

    public function reboot(): GateResult
    {
        $url = (string) $this->opt('relay.reboot_url', '');
        if ($url === '') {
            return GateResult::failure('URL перезагрузки не настроен.', 'not_configured');
        }

        return $this->hit($url, 1, 'reboot');
    }

    public function sipEnable(bool $enable): GateResult
    {
        return GateResult::failure('SIP не поддерживается универсальным реле.', 'unsupported');
    }

    public function raw(string $method, string $path, mixed $body = null): GateResult
    {
        $response = $this->http->request(strtoupper($method), $path, $this->httpOptions() + ($body === null ? [] : ['json' => $body]));

        return $response->ok()
            ? GateResult::success('OK', (array) ($response->json() ?? []), (array) ($response->json() ?? []), $response->durationMs, $response->status)
            : GateResult::failure('HTTP ' . $response->status, (string) $response->status, [], $response->status, $response->durationMs);
    }

    public function disconnect(): void
    {
    }

    private function hit(string $urlTemplate, int $channel, string $label): GateResult
    {
        if ($urlTemplate === '') {
            return GateResult::failure('Реле не настроено: укажите relay.open_url.', 'not_configured');
        }
        $url = strtr($urlTemplate, [
            '{channel}' => (string) $channel,
            '{ms}'      => (string) (int) $this->opt('lock.pulse_ms', 1000),
            '{token}'   => (string) ($this->opt('relay.token') ?? ''),
        ]);

        $started = microtime(true);
        try {
            $response = $this->http->request(
                (string) $this->opt('relay.method', 'GET'),
                $url,
                $this->httpOptions() + (($body = $this->opt('relay.body')) !== null ? ['body' => $body] : [])
            );
        } catch (Throwable $e) {
            return GateResult::failure('Реле недоступно: ' . $e->getMessage(), 'offline', [], null, (microtime(true) - $started) * 1000);
        }
        $ms = (microtime(true) - $started) * 1000;

        if (!$response->ok()) {
            $this->logger->channel('device')->warning('Relay command failed', ['device' => $this->id, 'command' => $label, 'status' => $response->status]);

            return GateResult::failure('Реле вернуло HTTP ' . $response->status, (string) $response->status, (array) ($response->json() ?? []), $response->status, $ms);
        }

        $successWhen = $this->opt('relay.success_when');
        if (is_string($successWhen) && $successWhen !== '' && !str_contains($response->body, $successWhen)) {
            return GateResult::failure('Ответ реле не содержит ожидаемого признака успеха.', 'unexpected_response', ['body' => substr($response->body, 0, 500)], $response->status, $ms);
        }

        return GateResult::success('Команда выполнена', ['command' => $label, 'channel' => $channel], (array) ($response->json() ?? []), $ms, $response->status);
    }

    /** @return array<string, mixed> */
    private function httpOptions(): array
    {
        $options = [
            'timeout'         => (int) $this->opt('timeout', 10),
            'connect_timeout' => (int) $this->opt('connect_timeout', 4),
            'retries'         => (int) $this->opt('retries', 1),
            'verify_tls'      => (bool) $this->opt('verify_tls', true),
            'user_agent'      => (string) $this->opt('user_agent', 'BAS-IP-Gate-Manager/2.0'),
            'headers'         => (array) $this->opt('relay.headers', []),
            'follow'          => (bool) $this->opt('relay.follow', false),
        ];

        $auth = (string) $this->opt('relay.auth', 'none');
        if ($auth === 'basic') {
            $options['basic'] = [(string) $this->opt('login', ''), (string) ($this->opt('password') ?? '')];
        } elseif ($auth === 'bearer') {
            $options['bearer'] = (string) ($this->opt('relay.token') ?? $this->opt('password') ?? '');
        }

        return $options;
    }

    private function opt(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->options, $key, $default);
    }
}
