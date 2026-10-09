<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * IP address helpers: normalisation, CIDR matching and allow-lists.
 */
final class Ip
{
    public static function normalize(string $ip): string
    {
        $ip = trim($ip);
        // Strip an optional port from IPv4 ("1.2.3.4:5678" is not valid but shows up in logs).
        if (substr_count($ip, ':') === 1 && str_contains($ip, ':')) {
            $candidate = substr($ip, 0, (int) strrpos($ip, ':'));
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $candidate;
            }
        }
        // IPv4-mapped IPv6 → IPv4
        if (str_starts_with($ip, '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mapped;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function isValid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @param array<int, string> $rules exact IPs and/or CIDR blocks
     */
    public static function matches(string $ip, array $rules): bool
    {
        $ip = self::normalize($ip);
        foreach ($rules as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '' || $rule === '*') {
                continue;
            }
            if ($rule === $ip) {
                return true;
            }
            if (str_contains($rule, '/') && self::inCidr($ip, $rule)) {
                return true;
            }
            if (self::wildcardMatch($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null) {
            return false;
        }
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits  = (int) $bits;
        $total = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $total) {
            return false;
        }
        $mask = str_repeat("\xff", intdiv($bits, 8));
        $remainder = $bits % 8;
        if ($remainder > 0) {
            $mask .= chr(0xff << (8 - $remainder) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /** "192.168.*" / "10.0.0.*" style rules. */
    public static function wildcardMatch(string $ip, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return false;
        }
        $regex = '/^' . str_replace('\*', '\d{1,3}', preg_quote($pattern, '/')) . '$/';

        return (bool) preg_match($regex, $ip);
    }

    /**
     * @param array<int, string> $ips
     * @param array<int, string> $cidrs
     */
    public static function allowed(string $ip, array $ips, array $cidrs): bool
    {
        if ($ips === [] && $cidrs === []) {
            return true;                 // no allow-list configured → everything passes
        }

        return self::matches($ip, array_merge($ips, $cidrs));
    }

    /** Anonymise for public logs: 1.2.3.4 → 1.2.0.0 */
    public static function anonymize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Keep the /24 so an operator can still tell "the office" from
            // "a random host", but drop the host part (GDPR/152-FZ friendly).
            $parts = explode('.', $ip);

            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.x';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) ?: $ip;
            }
        }

        return $ip;
    }
}
