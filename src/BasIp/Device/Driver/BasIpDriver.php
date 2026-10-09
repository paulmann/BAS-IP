<?php

declare(strict_types=1);

namespace BasIp\Device\Driver;

use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\HttpClient;
use BasIp\Core\HttpResponse;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use BasIp\Device\GateDriver;
use BasIp\Device\GateResult;
use BasIp\Support\Arr;
use BasIp\Support\Fs;
use RuntimeException;
use Throwable;

/**
 * BAS-IP Camdroid panel driver (`/api/v0`).
 *
 * https://developers.bas-ip.com/Camdroid-panels/
 *
 * This is the direct successor of the original `bas_ip.php`. Improvements over
 * v1.x:
 *   • token cache with a refresh *skew* (we re-authenticate before expiry
 *     instead of failing the first request after it)
 *   • three token stores: database (default, survives restarts and works across
 *     PHP-FPM workers), file (chmod 0600) and cache
 *   • retries with back-off, connect timeouts, circuit breaker (via HttpClient)
 *   • structured {@see GateResult} instead of echoing raw JSON
 *   • credentials are never logged; the URL is redacted before it hits the log
 *   • TLS verification is configurable per device (panels use self-signed certs)
 *   • explicit `logout` + token invalidation after a reboot
 */
final class BasIpDriver implements GateDriver
{
    /** @var array<string, mixed> */
    private array $options = [];

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly ?Cache $cache = null,
        private readonly string $id = 'main',
        private readonly ?Database $db = null,
    ) {
        $this->options = (array) $config->section('device');
    }

    public function name(): string  { return 'basip'; }
    public function label(): string { return 'BAS-IP Camdroid (/api/v0)'; }

    public function configure(array $options): void
    {
        $this->options = array_merge($this->options, array_filter($options, static fn ($v) => $v !== null && $v !== ''));
        $this->token = null;
        $this->tokenExpiresAt = 0;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['open', 'close', 'status', 'time', 'reboot', 'sip', 'camera', 'raw'], true);
    }

    /* ─────────────────────────── commands ─────────────────────────── */

    public function open(int $channel = 1): GateResult
    {
        $endpoint = (string) $this->opt('lock.endpoint', 'access/general/lock/open/remote/accepted/{channel}');
        $endpoint = str_replace(['{channel}', '{lock}'], [(string) $channel, (string) $channel], $endpoint);

        return $this->call('GET', $endpoint, null, 'open', true);
    }

    public function close(int $channel = 1): GateResult
    {
        $endpoint = (string) $this->opt('lock.close_endpoint', 'access/general/lock/close/remote/{channel}');
        $endpoint = str_replace('{channel}', (string) $channel, $endpoint);

        return $this->call('GET', $endpoint, null, 'close', true);
    }

    public function status(): GateResult
    {
        // `sip/status` is the cheapest endpoint that also reports registration state.
        $result = $this->call('GET', 'sip/status', null, 'status', false);
        if (!$result->ok) {
            return $result;
        }

        $data = $result->data;
        $time = $this->time();
        if ($time->ok) {
            $data['device_time'] = $time->data;
        }

        return GateResult::success('Статус получен', $data, $result->raw, $result->durationMs, $result->status);
    }

    public function time(): GateResult
    {
        $result = $this->call('GET', 'device/time', null, 'time', false);
        if (!$result->ok) {
            return $result;
        }
        $raw = $result->raw;
        $unix = isset($raw['device_time_unix']) ? (int) $raw['device_time_unix'] : (isset($raw['unix']) ? (int) $raw['unix'] : 0);
        $tz   = (string) ($raw['device_timezone'] ?? $raw['timezone'] ?? 'UTC');
        $drift = $unix > 0 ? $unix - $this->clock->now() : 0;

        return GateResult::success('Время получено', [
            'device_time_unix' => $unix,
            'device_timezone'  => $tz,
            'device_time'      => $unix > 0 ? date('Y-m-d H:i:s', $unix) : null,
            'drift_seconds'    => $drift,
            'drift_ok'         => abs($drift) <= 120,
        ], $raw, $result->durationMs, $result->status);
    }

    public function snapshot(): ?string
    {
        if (!$this->opt('camera.enabled', true)) {
            return null;
        }
        $mode = (string) $this->opt('camera.mode', 'snapshot');
        if ($mode === 'off') {
            return null;
        }

        $ttl = max(0, (int) $this->opt('camera.cache_ttl', 2));
        $cacheKey = 'device.' . $this->id . '.snapshot';
        if ($ttl > 0 && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        // 1) Ask the panel for the list of IP cameras and use the first snapshot URL.
        $cameras = $this->cameras();
        $url = null;
        foreach ((array) $cameras->get('cameras', []) as $camera) {
            if (isset($camera['snapshot_url']) && is_string($camera['snapshot_url']) && $camera['snapshot_url'] !== '') {
                $url = $camera['snapshot_url'];
                break;
            }
            if (isset($camera['url']) && is_string($camera['url']) && $camera['url'] !== '') {
                $url = $camera['url'];
                break;
            }
        }
        $url ??= (string) ($this->opt('camera.snapshot_url') ?? '');
        if ($url === '') {
            $url = rtrim((string) $this->opt('base_url'), '/') . '/../../snapshot.jpg';
        }

        $response = $this->http->request('GET', $url, $this->httpOptions() + [
            'bearer' => $this->tokenOrNull(),
            'accept' => 'image/jpeg,image/*',
            'timeout' => (int) $this->opt('camera.timeout', 8),
            'retries' => 0,
        ]);

        if (!$response->ok() || $response->body === '' || !str_contains((string) $response->header('content-type'), 'image')) {
            $this->logger->channel('device')->debug('Snapshot unavailable', ['device' => $this->id, 'status' => $response->status]);

            return null;
        }

        if ($ttl > 0 && $this->cache !== null) {
            $this->cache->put($cacheKey, $response->body, $ttl);
        }

        return $response->body;
    }

    public function cameras(): GateResult
    {
        $result = $this->call('GET', (string) $this->opt('camera.endpoint', 'security/ipcam'), null, 'cameras', false);
        if (!$result->ok) {
            return $result;
        }
        $raw = $result->raw;
        $list = [];
        $candidates = $raw['cameras'] ?? $raw['ipcam'] ?? $raw['data'] ?? (isset($raw[0]) ? $raw : []);
        foreach ((array) $candidates as $camera) {
            if (!is_array($camera)) {
                continue;
            }
            $list[] = [
                'id'           => $camera['id'] ?? $camera['channel'] ?? null,
                'name'         => (string) ($camera['name'] ?? $camera['title'] ?? 'Камера'),
                'url'          => $camera['url'] ?? $camera['stream'] ?? null,
                'snapshot_url' => $camera['snapshot'] ?? $camera['snapshot_url'] ?? null,
                'enabled'      => (bool) ($camera['enable'] ?? $camera['enabled'] ?? true),
            ];
        }
        if ($list === [] && ($stream = $this->opt('camera.stream_url')) !== null) {
            $list[] = ['id' => 1, 'name' => 'Поток', 'url' => (string) $stream, 'snapshot_url' => null, 'enabled' => true];
        }

        return GateResult::success('Камеры получены', ['cameras' => $list, 'count' => count($list)], $raw, $result->durationMs, $result->status);
    }

    public function reboot(): GateResult
    {
        $result = $this->call('GET', 'system/reboot/run', null, 'reboot', true);
        // The panel drops its session when it goes down — forget the token.
        $this->logout();
        $this->invalidateToken();

        return $result;
    }

    public function sipEnable(bool $enable): GateResult
    {
        return $this->call('POST', 'device/sip/enable', [
            'sip_enable'      => $enable,
            'is_reregister'   => true,
            'reregister_time' => (int) $this->opt('sip.reregister_time', 315),
        ], $enable ? 'sip_enable' : 'sip_disable', true);
    }

    public function raw(string $method, string $path, mixed $body = null): GateResult
    {
        return $this->call(strtoupper($method), ltrim($path, '/'), $body, 'raw', true);
    }

    public function ping(): GateResult
    {
        $started = microtime(true);
        try {
            $token = $this->authenticate();
        } catch (Throwable $e) {
            return GateResult::failure('Панель недоступна: ' . $e->getMessage(), 'offline', [], null, (microtime(true) - $started) * 1000);
        }

        return GateResult::success('Панель на связи', [
            'authenticated' => true,
            'token_preview' => substr($token, 0, 4) . '…',
            'base_url'      => (string) $this->opt('base_url'),
        ], [], (microtime(true) - $started) * 1000);
    }

    public function disconnect(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;
    }

    /* ─────────────────────────── auth & token cache ─────────────────────────── */

    /** @throws Throwable */
    private function authenticate(): string
    {
        $store = (string) $this->opt('token.store', 'db');
        $skew  = (int) $this->opt('token.refresh_skew', 120);

        if ($this->token !== null && $this->tokenExpiresAt - $skew > $this->clock->now()) {
            return $this->token;
        }

        $cached = $this->readCachedToken($store);
        if ($cached !== null && $cached['expires_at'] - $skew > $this->clock->now()) {
            $this->token = $cached['token'];
            $this->tokenExpiresAt = (int) $cached['expires_at'];

            return $this->token;
        }

        $login = (string) $this->opt('login', 'admin');
        $password = (string) ($this->opt('password') ?? '');
        $url = $this->url('login') . '?' . http_build_query(['username' => $login, 'password' => $password]);

        $response = $this->http->request('GET', $url, $this->httpOptions() + ['retries' => 1]);
        if (!$response->ok()) {
            throw new RuntimeException($this->describeFailure($response));
        }
        $data = $response->json();
        $token = null;
        foreach (['token', 'access_token', 'data.token', 'result.token'] as $path) {
            $value = Arr::get((array) $data, $path);
            if (is_string($value) && $value !== '') {
                $token = $value;
                break;
            }
        }
        if ($token === null) {
            throw new RuntimeException('Панель не вернула токен авторизации.');
        }

        $ttl = (int) $this->opt('token.ttl', 1600);
        $expires = $this->clock->now() + max(60, $ttl);
        $this->token = $token;
        $this->tokenExpiresAt = $expires;
        $this->writeCachedToken($store, $token, $expires);

        $this->logger->channel('device')->info('Device token refreshed', [
            'device' => $this->id, 'expires_in' => max(60, $ttl), 'store' => $store,
        ]);

        return $token;
    }

    /** @return array{token: string, expires_at: int}|null */
    private function readCachedToken(string $store): ?array
    {
        try {
            if ($store === 'file') {
                $file = $this->tokenFile();
                if (!is_file($file)) {
                    return null;
                }
                $data = json_decode((string) @file_get_contents($file), true);
                if (!is_array($data) || empty($data['token'])) {
                    return null;
                }

                return ['token' => (string) $data['token'], 'expires_at' => (int) ($data['expires_at'] ?? ((int) ($data['timestamp'] ?? 0) + (int) $this->opt('token.ttl', 1600)))];
            }
            if ($store === 'cache' && $this->cache !== null) {
                $data = $this->cache->get('device.' . $this->id . '.token');
                if (is_array($data) && !empty($data['token'])) {
                    return ['token' => (string) $data['token'], 'expires_at' => (int) ($data['expires_at'] ?? 0)];
                }

                return null;
            }
            // Default: a tiny row in app_state, shared by every PHP-FPM worker.
            $raw = $this->dbState('get');
            if ($raw === null) {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['token'])) {
                return null;
            }

            return ['token' => (string) $data['token'], 'expires_at' => (int) ($data['expires_at'] ?? 0)];
        } catch (Throwable) {
            return null;
        }
    }

    private function writeCachedToken(string $store, string $token, int $expiresAt): void
    {
        $payload = ['token' => $token, 'expires_at' => $expiresAt, 'device' => $this->id, 'at' => $this->clock->format()];
        try {
            if ($store === 'file') {
                Fs::put($this->tokenFile(), (string) json_encode($payload), 0600);

                return;
            }
            if ($store === 'cache' && $this->cache !== null) {
                $this->cache->put('device.' . $this->id . '.token', $payload, max(60, $expiresAt - $this->clock->now()));

                return;
            }
            $this->dbState('set', (string) json_encode($payload));
        } catch (Throwable $e) {
            $this->logger->channel('device')->warning('Token cache write failed', ['error' => $e->getMessage(), 'store' => $store]);
        }
    }

    private function invalidateToken(): void
    {
        try {
            $store = (string) $this->opt('token.store', 'db');
            if ($store === 'file' && is_file($this->tokenFile())) {
                @unlink($this->tokenFile());
            } elseif ($store === 'cache' && $this->cache !== null) {
                $this->cache->forget('device.' . $this->id . '.token');
            } else {
                $this->dbState('delete');
            }
        } catch (Throwable) {
            // non-fatal
        }
    }

    /**
     * Token persistence through `app_state` (no dedicated table needed).
     * Falls back to the file store when the DB is unavailable.
     */
    private function dbState(string $op, ?string $value = null): ?string
    {
        $key = 'device.token.' . $this->id;
        $db = $this->db;
        if ($db === null) {
            return null;
        }
        try {
            if ($op === 'get') {
                $row = $db->table('app_state')->where('key', '=', $key)->first();

                return $row === null ? null : (string) ($row['value'] ?? '');
            }
            if ($op === 'delete') {
                $db->table('app_state')->where('key', '=', $key)->delete();

                return null;
            }
            $db->table('app_state')->upsert([
                'key' => $key, 'value' => $value, 'updated_at' => $this->clock->format(),
            ], ['key']);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function logout(): void
    {
        if ($this->token === null) {
            return;
        }
        try {
            $this->http->request('GET', $this->url('logout'), $this->httpOptions() + ['bearer' => $this->token, 'retries' => 0, 'timeout' => 4]);
        } catch (Throwable) {
            // ignore — the device is rebooting anyway
        }
    }

    private function tokenFile(): string
    {
        $configured = $this->opt('token.file');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->config->getPath('tokens') . '/device-' . preg_replace('/[^a-z0-9_-]/i', '', $this->id) . '.token';
    }

    private function tokenOrNull(): ?string
    {
        try {
            return $this->authenticate();
        } catch (Throwable) {
            return null;
        }
    }

    /* ─────────────────────────── transport ─────────────────────────── */

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, mixed $body, string $label, bool $needsAuth): GateResult
    {
        $started = microtime(true);
        $options = $this->httpOptions();
        $options['accept'] = 'application/json';

        if ($needsAuth || $this->opt('always_auth', true)) {
            try {
                $options['bearer'] = $this->authenticate();
            } catch (Throwable $e) {
                return GateResult::failure($e->getMessage(), 'offline', [], null, (microtime(true) - $started) * 1000);
            }
        }

        $url = $this->url($path);
        $response = $method === 'GET'
            ? $this->http->request('GET', $url, $options)
            : $this->http->request($method, $url, $options + ($body === null ? [] : ['json' => $body]));

        $ms = (microtime(true) - $started) * 1000;
        $data = $response->json() ?? [];

        if (!$response->ok()) {
            // 401/403 → the cached token died; drop it and retry once.
            if (in_array($response->status, [401, 403], true)) {
                $this->token = null;
                $this->invalidateToken();
                try {
                    $options['bearer'] = $this->authenticate();
                    $response = $method === 'GET'
                        ? $this->http->request('GET', $url, $options)
                        : $this->http->request($method, $url, $options + ($body === null ? [] : ['json' => $body]));
                    $data = $response->json() ?? [];
                    $ms = (microtime(true) - $started) * 1000;
                } catch (Throwable $e) {
                    return GateResult::failure('Повторная авторизация не удалась: ' . $e->getMessage(), 'unauthorized', $data, $response->status, $ms);
                }
            }
            if (!$response->ok()) {
                $this->logger->channel('device')->warning('Device command failed', [
                    'device' => $this->id, 'command' => $label, 'status' => $response->status, 'ms' => round($ms),
                ]);

                return GateResult::failure(
                    $this->describeFailure($response),
                    (string) $response->status,
                    $data,
                    $response->status,
                    $ms
                );
            }
        }

        $this->logger->channel('device')->debug('Device command ok', [
            'device' => $this->id, 'command' => $label, 'status' => $response->status, 'ms' => round($ms),
        ]);

        return GateResult::success($label, $data, $data, $ms, $response->status);
    }

    private function url(string $path): string
    {
        $base = rtrim((string) $this->opt('base_url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('Не указан base_url устройства.');
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function httpOptions(): array
    {
        return [
            'timeout'         => (int) $this->opt('timeout', 10),
            'connect_timeout' => (int) $this->opt('connect_timeout', 4),
            'retries'         => (int) $this->opt('retries', 2),
            'retry_delay_ms'  => (int) $this->opt('retry_delay_ms', 250),
            'verify_tls'      => (bool) $this->opt('verify_tls', true),
            'ca_bundle'       => $this->opt('ca_bundle'),
            'user_agent'      => (string) $this->opt('user_agent', 'BAS-IP-Gate-Manager/2.0'),
            'proxy'           => $this->opt('proxy'),
            'follow'          => false,
        ];
    }

    /** Read a nested option from the merged device options. */
    private function opt(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->options, $key, $default);
    }

    private function describeFailure(HttpResponse $response): string
    {
        if ($response->error !== null) {
            return match (true) {
                $response->status === 0 && str_contains(strtolower($response->error), 'timed out') => 'Панель не отвечает (таймаут).',
                $response->status === 0 => 'Панель недоступна: ' . $response->error,
                default => 'Ошибка панели: ' . $response->error,
            };
        }

        $data = $response->json();
        $message = is_array($data) ? (Arr::get($data, 'error') ?? Arr::get($data, 'message') ?? null) : null;

        return sprintf('Панель вернула HTTP %d%s', $response->status, is_string($message) && $message !== '' ? ': ' . $message : '');
    }
}
