<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Arr;
use BasIp\Support\Env;
use BasIp\Support\Fs;
use RuntimeException;

/**
 * Immutable-ish configuration registry.
 *
 * Layering (later layers win):
 *   1. `config/config.php`                       — shipped defaults, no secrets
 *   2. `.bas-ip.config.local.php`                — YOUR machine, git-ignored
 *   3. `config/.bas-ip.config.local.php`         — alternative location
 *   4. `config/config.local.php`                 — alternative location
 *   5. `.env`                                    — container friendly
 *   6. `BASIP_*` environment variables           — highest precedence
 *   7. runtime overrides (`Config::override()`)  — used by the installer
 *
 * Secrets may be referenced as `${secret:NAME}` and are read from an encrypted
 * keystore (`storage/app/.secrets.php`), or `${ENV:NAME}` for plain env vars.
 */
final class Config
{
    /** Names of the local override files, in lookup order. */
    public const LOCAL_FILES = [
        '.bas-ip.config.local.php',
        'config/.bas-ip.config.local.php',
        'config/config.local.php',
        'config/.config.local.php',
    ];

    /** Legacy v1.x config keys → new dotted keys. */
    private const LEGACY_MAP = [
        'baseUrl'    => 'device.base_url',
        'login'      => 'device.login',
        'password'   => 'device.password',
        'tokenTtl'   => 'device.token.ttl',
        'tokenFile'  => 'device.token.file',
        'allowedIps' => 'auth.ip_allowlist.ips',
    ];

    /** @var array<string, mixed> */
    private array $items = [];

    /** @var array<int, string> */
    private array $sources = [];

    /** @var array<string, mixed> */
    private array $legacyDetected = [];

    private ?string $root = null;
    private ?Secrets $secrets = null;
    private bool $booted = false;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public static function boot(string $root): self
    {
        $config = new self($root);
        $config->load();

        return $config;
    }

    public function load(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // .env is read early so `${ENV:…}` placeholders can be resolved.
        Env::load($this->root . '/.env');

        $defaultsFile = $this->root . '/config/config.php';
        if (!is_file($defaultsFile)) {
            throw new RuntimeException('Default configuration not found: ' . $defaultsFile);
        }
        /** @var array<string, mixed> $defaults */
        $defaults = require $defaultsFile;
        $this->sources[] = Fs::relative($defaultsFile, $this->root);

        // Resolve the paths section first so storage-dependent layers can use it.
        $this->items = $defaults;
        $this->applyPathsOverrides();

        $this->secrets = new Secrets($this->getPath('storage') . '/app/.secrets.php', $this->getRawKey());

        $merged = $defaults;
        foreach (self::LOCAL_FILES as $relative) {
            $file = $this->root . '/' . $relative;
            if (!is_file($file)) {
                continue;
            }
            /** @var array<string, mixed>|null $local */
            $local = require $file;
            if (!is_array($local)) {
                continue;
            }
            $merged = Arr::mergeDeep($merged, $local);
            $this->sources[] = Fs::relative($file, $this->root) . (is_readable($file) ? '' : ' (unreadable)');
        }

        // Legacy v1.x `config.php` in the project root (flat array with credentials).
        $legacyFile = $this->root . '/config.php';
        if (is_file($legacyFile) && !is_file($this->root . '/config/.bas-ip.config.local.php')) {
            /** @var array<string, mixed>|null $legacy */
            $legacy = require $legacyFile;
            if (is_array($legacy) && (isset($legacy['baseUrl']) || isset($legacy['allowedIps']))) {
                $converted = $this->convertLegacy($legacy);
                $merged = Arr::mergeDeep($merged, $converted);
                $this->legacyDetected = $converted;
                $this->sources[] = 'config.php (legacy v1.x, auto-converted)';
            }
        }

        $this->items = Arr::mergeDeep($merged, $this->envOverrides());
        $this->items = $this->resolvePlaceholders($this->items);
        $this->applyPathsOverrides();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function forget(string $key): void
    {
        Arr::forget($this->items, $key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    public function section(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);

        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $v;
    }

    /** @return array<int, mixed> */
    public function list(string $key): array
    {
        $v = $this->get($key, []);
        if (is_string($v)) {
            $v = array_map('trim', explode(',', $v));
        }

        return is_array($v) ? array_values($v) : [];
    }

    public function path(string $key, ?string $default = null): string
    {
        return $this->getPath($key, $default);
    }

    public function getPath(string $key, ?string $default = null): string
    {
        $value = $this->get('paths.' . $key, $default);
        if (!is_string($value) || $value === '') {
            $value = $default ?? $this->root;
        }

        return str_starts_with($value, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $value)
            ? $value
            : $this->root . '/' . ltrim($value, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array<int, string> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string, mixed> */
    public function legacyDetected(): array
    {
        return $this->legacyDetected;
    }

    public function secrets(): Secrets
    {
        return $this->secrets ??= new Secrets($this->getPath('storage') . '/app/.secrets.php', $this->getRawKey());
    }

    /**
     * Configuration safe to render in the admin UI / diagnostics page.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $redact = (array) $this->get('security.audit.redact', []);

        return Arr::redact($this->items, $redact);
    }

    /** Runtime override (used by the web installer before the file is written). */
    public function override(array $values): void
    {
        $this->items = Arr::mergeDeep($this->items, $values);
    }

    /** Whether the app has a usable local configuration file. */
    public function hasLocalFile(): bool
    {
        foreach (self::LOCAL_FILES as $relative) {
            if (is_file($this->root . '/' . $relative)) {
                return true;
            }
        }

        return false;
    }

    public function localFilePath(): string
    {
        return $this->root . '/.bas-ip.config.local.php';
    }

    /** The application encryption key (raw bytes) — created by the installer. */
    public function appKey(): ?string
    {
        $key = $this->get('app.key');
        if (!is_string($key) || $key === '') {
            return null;
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return strlen($key) === 32 ? $key : substr(hash('sha256', $key, true), 0, 32);
    }

    private function getRawKey(): ?string
    {
        $key = $this->items['app']['key'] ?? null;
        if (is_string($key) && $key !== '') {
            return str_starts_with($key, 'base64:') ? substr($key, 7) : $key;
        }

        return null;
    }

    /** `paths.*` entries may come from the environment; make them absolute. */
    private function applyPathsOverrides(): void
    {
        $paths = $this->items['paths'] ?? [];
        if (!is_array($paths)) {
            return;
        }
        foreach ($paths as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $this->items['paths'][$name] = str_starts_with($value, '/')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $value)
                ? $value
                : $this->root . '/' . ltrim($value, '/');
        }
    }

    /**
     * BASIP_SECTION__KEY=value  →  section.key
     * BASIP_DB__CONNECTIONS__MYSQL__PASSWORD=x → db.connections.mysql.password
     *
     * @return array<string, mixed>
     */
    private function envOverrides(): array
    {
        $out = [];
        $vars = $_ENV + $_SERVER + (getenv() ?: []);
        foreach ($vars as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'BASIP_') || !is_scalar($value)) {
                continue;
            }
            $key = strtolower(str_replace('__', '.', substr($name, 6)));
            $key = str_replace('_', '.', $key);
            if ($key === '' || !is_string($value)) {
                continue;
            }
            $cast = Env::get((string) $name);
            Arr::set($out, $key, $cast === null ? $value : $cast);
        }

        // Convenience aliases.
        $aliases = [
            'APP_ENV'      => 'app.env',
            'APP_DEBUG'    => 'app.debug',
            'APP_KEY'      => 'app.key',
            'APP_URL'      => 'app.url',
            'APP_NAME'     => 'app.name',
            'APP_TIMEZONE' => 'app.timezone',
            'DB_DRIVER'    => 'db.default',
            'DB_HOST'      => null,
            'DB_PORT'      => null,
            'DB_DATABASE'  => null,
            'DB_USERNAME'  => null,
            'DB_PASSWORD'  => null,
            'DEVICE_BASE_URL' => 'device.base_url',
            'DEVICE_LOGIN'    => 'device.login',
            'DEVICE_PASSWORD' => 'device.password',
            'MAIL_DRIVER'   => 'notify.mail.driver',
            'MAIL_HOST'     => 'notify.mail.host',
            'MAIL_PORT'     => 'notify.mail.port',
            'MAIL_USERNAME' => 'notify.mail.username',
            'MAIL_PASSWORD' => 'notify.mail.password',
            'TELEGRAM_BOT_TOKEN' => 'notify.channels.telegram.bot_token',
            'GITHUB_TOKEN'  => 'update.token',
            'UPDATE_ENABLED' => 'update.enabled',
            'UPDATE_MODE'   => 'update.mode',
        ];
        $driver = Env::string('DB_DRIVER');
        foreach ($aliases as $envName => $key) {
            $value = Env::get($envName);
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === null) {
                // DB_HOST & friends apply to the *default* connection.
                $field = strtolower(substr($envName, 3));
                if ($driver !== null && $driver !== '') {
                    Arr::set($out, 'db.connections.' . $driver . '.' . $field, $value);
                }
                continue;
            }
            Arr::set($out, $key, $value);
        }

        return $out;
    }

    /** @param array<string, mixed> $legacy @return array<string, mixed> */
    private function convertLegacy(array $legacy): array
    {
        $out = [];
        foreach ($legacy as $oldKey => $value) {
            $newKey = self::LEGACY_MAP[$oldKey] ?? null;
            if ($newKey === null) {
                continue;
            }
            if ($newKey === 'auth.ip_allowlist.ips' && is_array($value) && $value !== []) {
                Arr::set($out, 'auth.ip_allowlist.enabled', true);
            }
            if ($newKey === 'device.token.file' && is_string($value) && $value !== '') {
                // v1 stored the token next to the code; keep it in storage instead.
                $value = $this->getPath('tokens') . '/device-main.token';
            }
            Arr::set($out, $newKey, $value);
        }
        if ($out !== []) {
            Arr::set($out, 'app.legacy_config_migrated', true);
        }

        return $out;
    }

    /** @param mixed $value */
    private function resolvePlaceholders($value): mixed
    {
        return Env::interpolate($value, function (string $name, string $fallback): string {
            $secrets = $this->secrets();
            if ($secrets->has($name)) {
                return $secrets->get($name, $fallback);
            }
            $fromEnv = Env::get($name);

            return $fromEnv === null ? $fallback : (string) $fromEnv;
        });
    }
}
