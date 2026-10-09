<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;
use RuntimeException;

/**
 * Encrypted key/value keystore for credentials referenced from the local
 * config as `${secret:DEVICE_PASSWORD}`.
 *
 * File: `storage/app/.secrets.php` (chmod 0600, git-ignored).
 * CLI:  `php bin/bas-ip secret:set DEVICE_PASSWORD`
 *
 * If no application key is configured the values are stored obfuscated
 * (base64) and the health page raises a warning — better than nothing, but you
 * should generate a key.
 */
final class Secrets
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $file,
        private readonly ?string $rawKey = null,
    ) {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!is_file($this->file)) {
            return $this->cache = [];
        }
        /** @var array<string, string>|null $data */
        $data = @include $this->file;

        return $this->cache = is_array($data) ? $data : [];
    }

    public function has(string $name): bool
    {
        return array_key_exists($this->norm($name), $this->all());
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $store = $this->all();
        $key   = $this->norm($name);
        if (!array_key_exists($key, $store)) {
            return $default;
        }
        $crypto = $this->crypto();
        $plain  = $crypto->decrypt((string) $store[$key], 'secret:' . $key);

        return $plain ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $store = $this->all();
        $key   = $this->norm($name);
        $store[$key] = $this->crypto()->encrypt($value, 'secret:' . $key);
        $this->write($store);
    }

    public function forget(string $name): bool
    {
        $store = $this->all();
        $key   = $this->norm($name);
        if (!array_key_exists($key, $store)) {
            return false;
        }
        unset($store[$key]);
        $this->write($store);

        return true;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        $names = array_keys($this->all());
        sort($names);

        return $names;
    }

    public function encrypted(): bool
    {
        return $this->crypto()->available();
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Re-encrypt everything with the current application key. */
    public function reencrypt(?string $oldRawKey = null): int
    {
        $store   = $this->all();
        $old     = new Crypto($oldRawKey ?? $this->rawKey);
        $new     = $this->crypto();
        $count   = 0;
        foreach ($store as $key => $value) {
            $plain = $old->decrypt((string) $value, 'secret:' . $key);
            if ($plain === null) {
                continue;
            }
            $store[$key] = $new->encrypt($plain, 'secret:' . $key);
            $count++;
        }
        if ($count > 0) {
            $this->write($store);
        }

        return $count;
    }

    private function write(array $store): void
    {
        ksort($store);
        $php = "<?php\n\n// BAS-IP encrypted secret store. Generated — do not edit by hand.\n// CLI: php bin/bas-ip secret:list | secret:set | secret:forget\n\nreturn "
            . var_export($store, true) . ";\n";
        Fs::put($this->file, $php, 0600);
        $this->cache = $store;
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        @opcache_invalidate($this->file, true);
    }

    private function crypto(): Crypto
    {
        return new Crypto($this->rawKey);
    }

    private function norm(string $name): string
    {
        $name = strtoupper(trim($name));
        if ($name === '') {
            throw new RuntimeException('Secret name cannot be empty.');
        }

        return preg_replace('/[^A-Z0-9_]/', '_', $name) ?? $name;
    }
}
