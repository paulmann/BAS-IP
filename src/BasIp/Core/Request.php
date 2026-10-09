<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Arr;
use BasIp\Support\Ip;

/**
 * Immutable-ish HTTP request value object.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $routeParams = [];

    private ?string $requestId = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $cookies,
        public readonly array $server,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly string $scheme = 'http',
    ) {
    }

    public static function capture(Config $config): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        // Support _method spoofing for plain HTML forms (PUT/PATCH/DELETE).
        if ($method === 'POST') {
            $spoof = $_POST['_method'] ?? null;
            if (is_string($spoof) && in_array(strtoupper($spoof), ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = strtoupper($spoof);
            }
        }

        $uri  = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = self::detectBasePath($server);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . trim(rawurldecode($path), '/');

        $headers = self::extractHeaders($server);
        $scheme  = self::detectScheme($server, $headers, $config);

        $rawBody = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $rawBody = (string) file_get_contents('php://input');
        }

        $post = $_POST;
        $contentType = $headers['content-type'] ?? '';
        if ($post === [] && $rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }

        return new self(
            method:  $method,
            path:    $path,
            query:   $_GET,
            post:    $post,
            files:   $_FILES,
            cookies: $_COOKIE,
            server:  $server,
            headers: $headers,
            body:    $rawBody,
            scheme:  $scheme,
        );
    }

    /**
     * Build a synthetic request — used by the CLI, the test-suite and the
     * Telegram webhook dispatcher.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public static function make(string $method, string $path, array $query = [], array $post = [], array $headers = [], string $body = ''): self
    {
        return new self(
            method: strtoupper($method),
            path: '/' . trim($path, '/'),
            query: $query,
            post: $post,
            files: [],
            cookies: [],
            server: ['REQUEST_METHOD' => strtoupper($method), 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'],
            headers: array_change_key_case($headers, CASE_LOWER),
            body: $body,
            scheme: 'http',
        );
    }

    /**
     * Build a fully-specified synthetic request (feature tests, smoke runs,
     * internal dispatch of the Telegram webhook).
     *
     * Unlike {@see make()} this accepts a real `$server` array, so proxy
     * detection, scheme and base-path resolution behave as in production.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $headers
     */
    public static function fake(
        string $method,
        string $path,
        array $post = [],
        array $server = [],
        array $files = [],
        array $cookies = [],
        string $body = '',
        array $headers = [],
    ): self {
        $method = strtoupper($method);
        $uri = '/' . ltrim($path, '/');
        $query = [];
        if (str_contains($uri, '?')) {
            [$uri, $queryString] = explode('?', $uri, 2);
            parse_str($queryString, $query);
        }

        $server = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $path,
            'SCRIPT_NAME'    => '/index.php',
            'PHP_SELF'       => '/index.php',
            'SERVER_NAME'    => 'localhost',
            'SERVER_PORT'    => '80',
            'REMOTE_ADDR'    => '127.0.0.1',
            'HTTP_HOST'      => 'localhost',
        ], $server);

        // `Accept: application/json` and XHR markers arrive via $server in real life.
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
        }

        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || (int) ($server['SERVER_PORT'] ?? 80) === 443
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            ? 'https' : 'http';

        return new self(
            method: $method,
            path: $uri === '' ? '/' : $uri,
            query: array_merge($query, (array) ($server['QUERY_ARRAY'] ?? [])),
            post: $post,
            files: $files,
            cookies: $cookies,
            server: $server,
            headers: array_change_key_case($headers, CASE_LOWER),
            body: $body,
            scheme: $scheme,
        );
    }

    /** Absolute URI prefix when the app lives in a sub-directory. */
    public static function detectBasePath(array $server): string
    {
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($script === '' || str_ends_with($script, '/index.php')) {
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

            return $dir === '/' ? '' : $dir;
        }

        return '';
    }

    public function basePath(): string
    {
        return self::detectBasePath($this->server);
    }

    public function url(string $path = ''): string
    {
        $base = rtrim($this->basePath(), '/');

        return $base . '/' . ltrim($path, '/');
    }

    public function fullUrl(): string
    {
        $host  = (string) ($this->headers['host'] ?? 'localhost');
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query);

        return $this->scheme . '://' . $host . $this->url($this->path) . $query;
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    public function isAjax(): bool
    {
        return strtolower((string) ($this->headers['x-requested-with'] ?? '')) === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        $accept = (string) ($this->headers['accept'] ?? '');

        return $this->isAjax()
            || str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/')
            || $this->is('post') && str_contains((string) ($this->headers['content-type'] ?? ''), 'application/json');
    }

    public function is(string ...$methods): bool
    {
        return in_array($this->method, array_map('strtoupper', $methods), true);
    }

    /** Merged GET + POST (+ JSON body). POST wins. */
    public function all(): array
    {
        return Arr::mergeDeep($this->query, $this->post);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (Arr::has($this->post, $key)) {
            return Arr::get($this->post, $key);
        }
        if (Arr::has($this->query, $key)) {
            return Arr::get($this->query, $key);
        }

        return $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = str_replace(',', '.', $this->str($key, (string) $default));

        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $v = $this->input($key);
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes', 'y'], true);
    }

    /** @return array<int, mixed> */
    public function array(string $key): array
    {
        $v = $this->input($key, []);
        if (is_string($v)) {
            $v = $v === '' ? [] : explode(',', $v);
        }

        return is_array($v) ? $v : [];
    }

    public function has(string $key): bool
    {
        return Arr::has($this->post, $key) || Arr::has($this->query, $key);
    }

    public function filled(string $key): bool
    {
        $v = $this->input($key);

        return $v !== null && $v !== '' && $v !== [];
    }

    /** @param array<int, string> $keys @return array<string, mixed> */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $v = $this->cookies[$key] ?? $default;

        return is_scalar($v) ? (string) $v : $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        $v = $this->headers[strtolower($key)] ?? $default;

        return is_scalar($v) ? (string) $v : $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        $key = $this->header('x-api-key');

        return $key !== null && $key !== '' ? $key : null;
    }

    /**
     * Best-effort client IP that respects trusted proxies only.
     */
    public function ip(Config $config): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        if (!$config->bool('app.behind_proxy', false)) {
            return Ip::normalize($remote);
        }
        $trusted = $config->list('app.trusted_proxies');
        if ($trusted !== [] && !Ip::matches($remote, $trusted)) {
            return Ip::normalize($remote);
        }
        $headerName = $config->string('app.ip_header', 'X-Forwarded-For');
        $raw = $this->header($headerName) ?? $remote;
        $first = trim(explode(',', $raw)[0]);

        return Ip::normalize($first !== '' ? $first : $remote);
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 512);
    }

    public function referer(): string
    {
        return (string) ($this->headers['referer'] ?? '');
    }

    public function requestId(): string
    {
        return $this->requestId ??= (string) ($this->header('x-request-id') ?: substr(bin2hex(random_bytes(8)), 0, 16));
    }

    public function withRequestId(string $id): self
    {
        $clone = clone $this;
        $clone->requestId = $id;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new UploadedFile(
            (string) ($file['tmp_name'] ?? ''),
            (string) ($file['name'] ?? 'upload'),
            (int) ($file['size'] ?? 0),
            (string) ($file['type'] ?? 'application/octet-stream'),
            (int) ($file['error'] ?? UPLOAD_ERR_OK),
        );
    }

    /** @return array<string, mixed> */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
                continue;
            }
            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $name => $value) {
                $headers[strtolower((string) $name)] = $value;
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private static function detectScheme(array $server, array $headers, Config $config): string
    {
        if (($server['HTTPS'] ?? '') === 'on' || ($server['REQUEST_SCHEME'] ?? '') === 'https') {
            return 'https';
        }
        if ((int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }
        if (!$config->bool('app.behind_proxy', false)) {
            return 'http';
        }
        $forwarded = strtolower((string) ($headers['x-forwarded-proto'] ?? ''));
        if (str_contains($forwarded, 'https')) {
            return 'https';
        }
        if (($headers['front-end-https'] ?? '') === 'on') {
            return 'https';
        }

        return 'http';
    }
}
