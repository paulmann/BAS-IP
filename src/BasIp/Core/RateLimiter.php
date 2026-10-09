<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;
use Throwable;

/**
 * Fixed-window rate limiter backed by the cache directory.
 *
 * Uses an exclusive file lock per bucket so concurrent requests from the same
 * client cannot slip through, and cleans up stale buckets opportunistically.
 */
final class RateLimiter
{
    private string $path;

    public function __construct(private readonly Config $config, private readonly Clock $clock)
    {
        $this->path = rtrim($config->getPath('cache'), '/') . '/ratelimit';
        try {
            Fs::ensureDir($this->path, 0770);
        } catch (Throwable) {
            $this->path = sys_get_temp_dir() . '/basip-rl';
            Fs::ensureDir($this->path, 0770);
        }
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retryAfter: int}
     */
    public function attempt(string $bucket, int $max, int $windowSeconds, int $cost = 1): array
    {
        if ($max <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'limit' => 0, 'reset' => 0, 'retryAfter' => 0];
        }
        $file   = $this->file($bucket);
        $now    = $this->clock->now();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Fail open — never lock a resident out of their own gate because of a disk problem.
            return ['allowed' => true, 'remaining' => $max, 'limit' => $max, 'reset' => $now + $windowSeconds, 'retryAfter' => 0];
        }

        try {
            flock($handle, LOCK_EX);
            $raw  = stream_get_contents($handle);
            $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
            if (!is_array($data) || ($data['reset'] ?? 0) <= $now) {
                $data = ['count' => 0, 'reset' => $now + $windowSeconds, 'started' => $now];
            }

            $allowed = ($data['count'] + $cost) <= $max;
            if ($allowed) {
                $data['count'] += $cost;
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($data));
                fflush($handle);
            }

            $remaining = max(0, $max - (int) $data['count']);
            $retryAfter = $allowed ? 0 : max(1, (int) $data['reset'] - $now);

            return [
                'allowed'    => $allowed,
                'remaining'  => $remaining,
                'limit'      => $max,
                'reset'      => (int) $data['reset'],
                'retryAfter' => $retryAfter,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function hit(string $bucket, int $max, int $window): array
    {
        return $this->attempt($bucket, $max, $window);
    }

    public function tooManyAttempts(string $bucket, int $max, int $window): bool
    {
        $data = $this->peek($bucket);

        return $data['count'] >= $max && $data['reset'] > $this->clock->now();
    }

    /** @return array{count: int, reset: int} */
    public function peek(string $bucket): array
    {
        $file = $this->file($bucket);
        if (!is_file($file)) {
            return ['count' => 0, 'reset' => 0];
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return ['count' => 0, 'reset' => 0];
        }
        if (($data['reset'] ?? 0) <= $this->clock->now()) {
            return ['count' => 0, 'reset' => 0];
        }

        return ['count' => (int) ($data['count'] ?? 0), 'reset' => (int) ($data['reset'] ?? 0)];
    }

    public function clear(string $bucket): void
    {
        @unlink($this->file($bucket));
    }

    public function increment(string $bucket, int $window): int
    {
        $result = $this->attempt($bucket, PHP_INT_MAX, $window);

        return max(0, PHP_INT_MAX - $result['remaining']);
    }

    /** Remove buckets that expired more than an hour ago. */
    public function gc(int $olderThan = 3600): int
    {
        $removed = 0;
        foreach (Fs::glob($this->path, '*.rl') as $file) {
            if ((int) @filemtime($file) < $this->clock->now() - $olderThan) {
                @unlink($file) && $removed++;
            }
        }

        return $removed;
    }

    private function file(string $bucket): string
    {
        return $this->path . '/' . hash('sha256', $bucket) . '.rl';
    }
}
