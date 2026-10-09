<?php

declare(strict_types=1);

namespace BasIp\Auth;

/**
 * TOTP (RFC 6238) + `otpauth://` provisioning URIs.
 *
 * Implemented from scratch on top of `hash_hmac` so 2FA works without any
 * Composer package. Compatible with Google Authenticator, Authy, 1Password,
 * FreeOTP and Aegis.
 */
final class Totp
{
    private const ALGORITHMS = ['sha1' => 'sha1', 'sha256' => 'sha256', 'sha512' => 'sha512'];
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly string $issuer = 'BAS-IP',
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly string $algorithm = 'sha1',
        private readonly int $window = 1,
    ) {
    }

    /** Generate a new base32 secret (default 160 bits). */
    public static function generateSecret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);
        $binary = '';
        foreach (str_split($random) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) === 5) {
                $secret .= self::BASE32[bindec($chunk)];
            }
        }

        return $secret;
    }

    public function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);
        $binary  = self::base32Decode($secret);
        if ($binary === '') {
            return '';
        }

        $packed = pack('N*', 0, $counter);
        $hash   = hash_hmac($this->algorithm(), $packed, $binary, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $part   = substr($hash, $offset, 4);
        $value  = unpack('N', $part)[1] & 0x7fffffff;

        return str_pad((string) ($value % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied code, tolerating clock drift of ±$window periods.
     * Constant-time comparison.
     */
    public function verify(string $secret, string $code, ?int $timestamp = null, ?int &$matchedCounter = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($code === '' || $secret === '') {
            return false;
        }
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        for ($i = -$this->window; $i <= $this->window; $i++) {
            $expected = $this->code($secret, ($counter + $i) * $this->period);
            if (hash_equals($expected, $code)) {
                $matchedCounter = $counter + $i;

                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $account, ?string $issuer = null): string
    {
        $issuer ??= $this->issuer;
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algorithm()),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /** Remaining seconds until the current code rotates — for the UI ring. */
    public function secondsRemaining(?int $timestamp = null): int
    {
        $timestamp ??= time();

        return $this->period - ($timestamp % $this->period);
    }

    public static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $out .= self::BASE32[bindec($chunk)];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $binary = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return '';
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    private function algorithm(): string
    {
        return self::ALGORITHMS[strtolower($this->algorithm)] ?? 'sha1';
    }
}
