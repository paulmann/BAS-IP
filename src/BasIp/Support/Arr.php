<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Array helpers with "dot" notation support.
 *
 * Dot notation understands list indices too: `db.connections.mysql.host`.
 * A literal key containing a dot can be addressed with a backslash: `some\.key`.
 */
final class Arr
{
    /** @param array<mixed> $array */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '' || $key === '*') {
            return $array;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $segment = null;
        $rest    = $key;
        foreach (self::segments($key) as $i => $s) {
            $segment = $s;
            $rest    = implode('.', array_slice(self::segments($key), $i + 1));
            break;
        }

        if ($segment === null || !is_array($array) || !array_key_exists($segment, $array)) {
            return $default;
        }
        if ($rest === '') {
            return $array[$segment];
        }

        return self::get($array[$segment], $rest, $default);
    }

    /**
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function set(array &$array, string $key, mixed $value): array
    {
        $segments = self::segments($key);
        $target   = &$array;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            /** @var string $segment */
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target[array_shift($segments)] = $value;

        return $array;
    }

    /** @param array<mixed> $array */
    public static function has(array $array, string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (array_key_exists($key, $array)) {
            return true;
        }
        $segments = self::segments($key);
        $target   = $array;
        foreach ($segments as $segment) {
            if (!is_array($target) || !array_key_exists($segment, $target)) {
                return false;
            }
            $target = $target[$segment];
        }

        return true;
    }

    /** @param array<mixed> $array */
    public static function forget(array &$array, string $key): void
    {
        $segments = self::segments($key);
        $last     = array_pop($segments);
        $target   = &$array;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                return;
            }
            $target = &$target[$segment];
        }
        unset($target[$last]);
    }

    /**
     * Recursive merge. Later arrays win for scalars and lists;
     * string-keyed arrays are merged deeply.
     *
     * @param array<mixed> ...$arrays
     * @return array<mixed>
     */
    public static function mergeDeep(array ...$arrays): array
    {
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_int($key)) {
                    $result[] = $value;
                    continue;
                }
                if (is_array($value) && isset($result[$key]) && is_array($result[$key]) && self::isAssoc($value)) {
                    $result[$key] = self::mergeDeep($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /** @param array<mixed> $array */
    public static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Return only the given keys (dot notation supported).
     *
     * @param array<mixed> $array
     * @param array<int, string> $keys
     * @return array<mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (self::has($array, $key)) {
                self::set($out, $key, self::get($array, $key));
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $array
     * @param array<int, string> $keys
     * @return array<mixed>
     */
    public static function except(array $array, array $keys): array
    {
        foreach ($keys as $key) {
            self::forget($array, $key);
        }

        return $array;
    }

    /**
     * Flatten to `dot.key => value` pairs.
     *
     * @param array<mixed> $array
     * @return array<string, mixed>
     */
    public static function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && self::isAssoc($value)) {
                $out += self::flatten($value, $name);
            } else {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Remove every value whose key matches one of the needles (case-insensitive substring match).
     *
     * @param array<mixed> $array
     * @param array<int, string> $needles
     * @return array<mixed>
     */
    public static function redact(
        array $array,
        array $needles = ['password', 'secret', 'token', 'key', 'otp', 'pin', 'recovery_codes', 'totp_secret', 'credit_card']
    ): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::redact($value, $needles);
                continue;
            }
            $lower = strtolower((string) $key);
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($lower, strtolower($needle))) {
                    $array[$key] = is_string($value) && $value === '' ? '' : '••••••';
                    break;
                }
            }
        }

        return $array;
    }

    /**
     * @param array<mixed> $array
     * @return array<int, mixed>
     */
    public static function values(array $array): array
    {
        return array_values($array);
    }

    /**
     * @param array<int, mixed> $list
     * @return array<int, array<int, mixed>>
     */
    public static function chunk(array $list, int $size): array
    {
        $size = max(1, $size);

        return array_values(array_chunk($list, $size));
    }

    /**
     * groupBy with a callback key.
     *
     * @template T
     * @param iterable<T> $items
     * @param callable(T): (string|int) $key
     * @return array<string|int, array<int, T>>
     */
    public static function groupBy(iterable $items, callable $key): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$key($item)][] = $item;
        }

        return $out;
    }

    /**
     * @param iterable<mixed> $items
     * @param callable(mixed): (string|int) $key
     * @return array<string|int, mixed>
     */
    public static function keyBy(iterable $items, callable $key): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$key($item)] = $item;
        }

        return $out;
    }

    /** Split a dotted key into segments, honouring `\.` escapes.
     * @return array<int, string> */
    private static function segments(string $key): array
    {
        $out   = [];
        $buf   = '';
        $len   = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            $ch = $key[$i];
            if ($ch === '\\' && $i + 1 < $len && $key[$i + 1] === '.') {
                $buf .= '.';
                $i++;
                continue;
            }
            if ($ch === '.') {
                $out[] = $buf;
                $buf   = '';
                continue;
            }
            $buf .= $ch;
        }
        $out[] = $buf;

        return $out;
    }
}
