<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;
use Throwable;

/**
 * Tiny cache with three drivers: file (default), APCu and an in-process array.
 *
 * Used for device status, GitHub release lookups, computed statistics and
 * compiled templates. Values are serialised with `igbinary` when available.
 */
final class Cache
{
    /** @var array<string, mixed> */
    private array $memory = [];

    private string $prefix;
    private string $path;
    private string $driver;

    public function __construct(private readonly Config $config, ?string $driver = null)
    {
        $this->driver = $driver ?? $config->string('cache.driver', 'file');
        $this->prefix = (string) $config->string('cache.prefix', 'basip');
        $this->path   = rtrim($config->getPath('cache'), '/');
        if ($this->driver === 'file') {
            try {
                Fs::ensureDir($this->path);
            } catch (Throwable) {
                $this->driver = 'array';
            }
        }
        if ($this->driver === 'apcu' && !function_exists('apcu_fetch')) {
            $this->driver = 'file';
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->read($key);
        if ($entry === null) {
            return $default;
        }
        if (isset($entry['exp']) && $entry['exp'] > 0 && $entry['exp'] < time()) {
            $this->forget($key);

            return $default;
        }

        return $entry['val'] ?? $default;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->config->int('cache.ttl', 300);
        $entry = ['val' => $value, 'exp' => $ttl > 0 ? time() + $ttl : 0, 'at' => time()];

        return $this->write($key, $entry);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key, $sentinel = new \stdClass());
        if ($value !== $sentinel) {
            return $value;
        }
        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function forget(string $key): bool
    {
        unset($this->memory[$key]);
        if ($this->driver === 'apcu') {
            return (bool) apcu_delete($this->namespaced($key));
        }
        if ($this->driver === 'file') {
            return @unlink($this->file($key));
        }

        return true;
    }

    /** Delete everything created by this application. */
    public function flush(?string $prefix = null): int
    {
        $count = 0;
        $this->memory = [];
        if ($this->driver === 'apcu') {
            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }

            return 0;
        }
        if ($this->driver === 'file') {
            $marker = $prefix !== null ? substr(hash('sha256', $prefix), 0, 8) : null;
            foreach (Fs::glob($this->path, '*.cache') as $file) {
                if ($marker === null || str_contains(basename($file), $marker)) {
                    if (@unlink($file)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /** Garbage-collect expired entries (run from cron). */
    public function gc(): int
    {
        if ($this->driver !== 'file') {
            return 0;
        }
        $removed = 0;
        foreach (Fs::glob($this->path, '*.cache') as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $entry = $this->decode($raw);
            if (!is_array($entry) || (isset($entry['exp']) && $entry['exp'] > 0 && $entry['exp'] < time())) {
                @unlink($file) && $removed++;
            }
        }

        return $removed;
    }

    /** Simple file lock helper for "run once per interval" jobs. */
    public function lock(string $key, int $ttl): bool
    {
        $lockKey = 'lock.' . $key;
        $file    = $this->file($lockKey);
        if ($this->driver !== 'file') {
            return true;
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $stat = fstat($handle);
        if (is_array($stat) && ($stat['mtime'] ?? 0) > 0 && $stat['mtime'] + $ttl > time() && trim((string) stream_get_contents($handle, -1, 0)) !== '') {
            flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) time());
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        if ($this->driver === 'array') {
            $entry = $this->memory[$key] ?? null;

            return is_array($entry) ? $entry : null;
        }
        if ($this->driver === 'apcu') {
            $ok = false;
            $entry = apcu_fetch($this->namespaced($key), $ok);

            return $ok && is_array($entry) ? $entry : null;
        }
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);

        return $raw === false ? null : $this->decode($raw);
    }

    /** @param array<string, mixed> $entry */
    private function write(string $key, array $entry): bool
    {
        if ($this->driver === 'array') {
            $this->memory[$key] = $entry;

            return true;
        }
        if ($this->driver === 'apcu') {
            return (bool) apcu_store($this->namespaced($key), $entry, max(0, (int) ($entry['exp'] ?? 0) - time()));
        }
        try {
            Fs::put($this->file($key), $this->encode($entry), 0640);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $entry */
    private function encode(array $entry): string
    {
        return base64_encode(serialize($entry));
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return null;
        }
        try {
            $value = @unserialize($decoded, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function file(string $key): string
    {
        return $this->path . '/' . $this->prefix . '.' . hash('sha256', $key) . '.cache';
    }

    private function namespaced(string $key): string
    {
        return $this->prefix . '.' . $key;
    }
}
