<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;
use Throwable;

/**
 * Hardened session wrapper.
 *
 *  * files stored in `storage/framework/sessions` (outside the web root
 *    whenever the recommended layout is used)
 *  * `HttpOnly`, `SameSite`, `Secure` (auto-detected) cookie flags
 *  * absolute lifetime + idle timeout
 *  * optional UA/IP fingerprint binding to defeat session hijacking
 *  * flash data for redirects
 */
final class Session
{
    private bool $started = false;
    private string $id = '';
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly Config $config,
        private readonly Request $request,
        private readonly Clock $clock,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;

            return;
        }

        $name   = $this->config->string('auth.session.name', 'basip_session');
        $prefix = $this->config->bool('security.cookies.prefix_secure') ? '__Secure-' : '';
        if ($prefix !== '' && !$this->request->isSecure()) {
            $prefix = '';
        }

        $path = $this->config->getPath('sessions');
        try {
            Fs::ensureDir($path, 0700);
        } catch (Throwable) {
            $path = sys_get_temp_dir();
        }

        $secure = $this->config->get('auth.session.secure');
        $secure = $secure === null ? $this->request->isSecure() : (bool) $secure;

        session_name($prefix . $name);
        session_save_path($path);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->request->basePath() !== '' ? $this->request->basePath() : '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => $this->config->bool('auth.session.httponly', true),
            'samesite' => $this->config->string('auth.session.samesite', 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) ($this->config->int('auth.session.lifetime', 720) * 60));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.serialize_handler', 'php_serialize');

        $this->started = @session_start() || session_status() === PHP_SESSION_ACTIVE;
        $this->id      = session_id() ?: '';
        $this->data    = &$_SESSION;

        $this->enforceLifetime();
        $this->enforceFingerprint();
        $this->touch();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function started(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function flush(): void
    {
        $id = $this->id;
        $this->data = [];
        $this->data['_id'] = $id;
    }

    /* ─────────────────────────── flash ─────────────────────────── */

    public function flash(string $key, mixed $value): void
    {
        $this->data['_flash']['new'][$key] = $value;
    }

    public function flashed(string $key, mixed $default = null): mixed
    {
        return $this->data['_flash']['old'][$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allFlashed(): array
    {
        return $this->data['_flash']['old'] ?? [];
    }

    public function reflash(): void
    {
        $old = $this->data['_flash']['old'] ?? [];
        $this->data['_flash']['new'] = array_merge($this->data['_flash']['new'] ?? [], $old);
    }

    public function ageFlash(): void
    {
        $this->data['_flash']['old'] = $this->data['_flash']['new'] ?? [];
        $this->data['_flash']['new'] = [];
    }

    /* ─────────────────────────── lifecycle ─────────────────────────── */

    public function regenerate(bool $deleteOld = true): void
    {
        if (PHP_SAPI === 'cli' || !$this->started) {
            $this->id = bin2hex(random_bytes(24));

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
            $this->id = session_id() ?: $this->id;
        }
        $this->touch();
    }

    public function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->data = [];

            return;
        }
        $this->data = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => $this->clock->now() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        $this->id = '';
    }

    public function touch(): void
    {
        $this->data['_last_activity'] = $this->clock->now();
        $this->data['_started_at'] ??= $this->clock->now();
        $this->data['_fingerprint'] = $this->fingerprint();
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    private function enforceLifetime(): void
    {
        $now     = $this->clock->now();
        $idle    = $this->config->int('auth.session.idle_timeout', 60) * 60;
        $abs     = $this->config->int('auth.session.lifetime', 720) * 60;
        $last    = (int) ($this->data['_last_activity'] ?? $now);
        $started = (int) ($this->data['_started_at'] ?? $now);

        if (($idle > 0 && $now - $last > $idle) || ($abs > 0 && $now - $started > $abs)) {
            $reason = ($abs > 0 && $now - $started > $abs) ? 'absolute-lifetime' : 'idle-timeout';
            $this->logger?->info('Session expired', ['reason' => $reason, 'session' => substr($this->id, 0, 8)]);
            $this->destroy();
            $this->start();
        }
    }

    private function enforceFingerprint(): void
    {
        if (!$this->config->bool('auth.session.fingerprint', true)) {
            return;
        }
        $stored = $this->data['_fingerprint'] ?? null;
        $actual = $this->fingerprint();
        if (is_string($stored) && $stored !== '' && !hash_equals($stored, $actual)) {
            $this->logger?->warning('Session fingerprint mismatch — session destroyed', [
                'session' => substr($this->id, 0, 8),
                'ip'      => $this->request->ip($this->config),
            ]);
            $this->destroy();
            $this->start();
        }
    }

    private function fingerprint(): string
    {
        $ua   = $this->request->userAgent();
        $salt = (string) $this->config->get('app.key', 'bas-ip');

        return hash_hmac('sha256', substr($ua, 0, 120) . '|' . $this->request->scheme, $salt);
    }
}
