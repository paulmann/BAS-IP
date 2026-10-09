<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Environment access + a tiny `.env` parser.
 *
 * `.env` is *not* required — the PHP local config file is the primary source —
 * but it is convenient for containers and 12-factor deployments.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $loaded = [];
    private static bool $parsed = false;

    public static function load(string $file): void
    {
        if (!is_readable($file)) {
            self::$parsed = true;

            return;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            self::$parsed = true;

            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            $line = preg_replace('/^export\s+/', '', $line) ?? $line;
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);
            if ($name === '' || $name === '0') {
                continue;
            }
            if (strlen($value) > 1) {
                $first = $value[0];
                $last  = substr($value, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                    }
                }
            }
            // Never clobber a real environment variable.
            self::$loaded[$name] = $value;
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        fclose($handle);
        self::$parsed = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false) {
            $value = self::$loaded[$key] ?? null;
        }
        if ($value === null || $value === '') {
            return $default;
        }

        return self::cast((string) $value);
    }

    public static function string(string $key, ?string $default = null): ?string
    {
        $v = self::get($key, $default);

        return $v === null ? null : (string) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on', 'y'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);

        return $v === null ? $default : (int) $v;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::get($key);

        return $v === null ? $default : (float) $v;
    }

    /** Comma-separated list. */
    public static function list(string $key, array $default = []): array
    {
        $v = self::get($key);
        if (!is_string($v) || trim($v) === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($x) => $x !== ''));
    }

    /**
     * Resolve `${ENV:NAME}` / `${ENV:NAME:fallback}` / `${secret:NAME}` placeholders.
     */
    public static function interpolate(mixed $value, ?callable $secretResolver = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::interpolate($v, $secretResolver);
            }

            return $value;
        }
        if (!is_string($value) || !str_contains($value, '${')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\$\{\s*(env|secret|config)\s*:\s*([^:}]+)\s*(?::\s*([^}]*))?\s*\}/i',
            static function (array $m) use ($secretResolver): string {
                $type = strtolower($m[1]);
                $name = trim($m[2]);
                $fallback = $m[3] ?? '';
                if ($type === 'secret' && $secretResolver !== null) {
                    $resolved = $secretResolver($name, $fallback);

                    return is_string($resolved) ? $resolved : $fallback;
                }

                $env = self::get($name);

                return $env === null ? $fallback : (string) $env;
            },
            $value
        );
    }

    private static function cast(string $value): mixed
    {
        $lower = strtolower($value);

        return match ($lower) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }

    /** All variables that were read from `.env` (never from the real environment). */
    public static function dotEnvValues(): array
    {
        return self::$loaded;
    }
}
