<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Str;
use RuntimeException;

/**
 * Application-level cryptography.
 *
 *  * AES-256-GCM (libsodium `crypto_aead_xchacha20poly1305_ietf` when available,
 *    OpenSSL otherwise) for secrets at rest.
 *  * HMAC-SHA256 for signed URLs (guest passes, e-mail reset tokens, API keys).
 *  * Constant-time comparison everywhere it matters.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    public function __construct(private readonly ?string $key = null)
    {
    }

    public function available(): bool
    {
        return $this->key !== null && strlen((string) $this->key) >= 16;
    }

    /** Derive a stable 32-byte sub-key for a purpose ("hmac", "aead", …). */
    public function deriveKey(string $purpose, int $length = 32): string
    {
        if ($this->key === null) {
            throw new RuntimeException('Application key is not configured — run `php bin/bas-ip key:generate`.');
        }

        return substr(hash_hkdf('sha256', $this->key, $length, $purpose), 0, $length);
    }

    public function encrypt(string $plain, string $purpose = 'default'): string
    {
        if (!$this->available()) {
            // Degrade gracefully: obfuscate, but make it obvious in the UI that
            // the value is NOT protected.
            return 'obf:v1:' . base64_encode($plain);
        }
        $key  = $this->deriveKey('aead:' . $purpose);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plain,
            $purpose,           // additional authenticated data
            $nonce,
            $key
        );

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload, string $purpose = 'default'): ?string
    {
        if (str_starts_with($payload, 'obf:v1:')) {
            $decoded = base64_decode(substr($payload, 7), true);

            return $decoded === false ? null : $decoded;
        }
        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;               // plain value stored before encryption was enabled
        }
        if (!$this->available()) {
            return null;
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false) {
            return null;
        }
        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) <= $nonceLen) {
            return null;
        }
        $nonce  = substr($raw, 0, $nonceLen);
        $cipher = substr($raw, $nonceLen);
        $plain  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            $purpose,
            $nonce,
            $this->deriveKey('aead:' . $purpose)
        );

        return $plain === false ? null : $plain;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) || str_starts_with($value, 'obf:v1:');
    }

    public function hmac(string $data, string $purpose = 'sign'): string
    {
        return hash_hmac('sha256', $data, $this->deriveKey('hmac:' . $purpose));
    }

    public function verifyHmac(string $data, string $signature, string $purpose = 'sign'): bool
    {
        return self::equals($this->hmac($data, $purpose), $signature);
    }

    /** Build `payload.signature` (both base64url) — used for guest links. */
    public function sign(array $payload, string $purpose = 'signed-url'): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode payload for signing.');
        }

        return self::base64UrlEncode($json) . '.' . self::base64UrlEncode($this->hmac($json, $purpose));
    }

    /** @return array<string, mixed>|null */
    public function unsign(string $signed, string $purpose = 'signed-url', ?int $maxAge = null): ?array
    {
        $parts = explode('.', $signed);
        if (count($parts) !== 2) {
            return null;
        }
        [$b64payload, $b64sig] = $parts;
        $json = self::base64UrlDecode($b64payload);
        $sig  = self::base64UrlDecode($b64sig);
        if ($json === null || $sig === null || !$this->verifyHmac($json, $sig, $purpose)) {
            return null;
        }
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        $now = time();
        // Explicit expiry wins; `iat` + $maxAge is the fallback for open-ended links.
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < $now) {
            return null;
        }
        if ($maxAge !== null && isset($payload['iat']) && is_numeric($payload['iat'])) {
            if ((int) $payload['iat'] + $maxAge < $now) {
                return null;
            }
        }

        return $payload;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): ?string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function randomBase64(int $bytes = 32): string
    {
        return 'base64:' . base64_encode(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        return 'hash:sha256:' . hash('sha256', $token);
    }

    public static function equals(?string $known, ?string $user): bool
    {
        if ($known === null || $user === null) {
            return false;
        }

        return hash_equals($known, $user);
    }

    /** A short, non-reversible fingerprint for display in the admin UI. */
    public static function fingerprint(string $value, int $length = 8): string
    {
        return strtoupper(substr(hash('sha256', $value), 0, $length));
    }

    public static function uuidv4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function mask(string $value): string
    {
        return Str::mask($value, 2, 2);
    }
}
