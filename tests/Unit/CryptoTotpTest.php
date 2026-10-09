<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Auth\Totp;
use BasIp\Core\Crypto;
use BasIp\Tests\Support\TestCase;

/**
 * Cryptographic primitives: encryption of secrets, token hashing, signed
 * payloads and RFC 6238 TOTP.
 */
final class CryptoTotpTest extends TestCase
{
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new Crypto(str_repeat('k', 32));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        if (!$this->crypto->available()) {
            return;     // sodium missing — nothing to test
        }
        $plain = 'Секретный пароль панели #123';
        $cipher = $this->crypto->encrypt($plain, 'device');
        $this->assertNotSame($plain, $cipher);
        $this->assertStringContains('enc:v1:', $cipher);
        $this->assertSame($plain, $this->crypto->decrypt($cipher, 'device'));
    }

    public function testContextMismatchFailsToDecrypt(): void
    {
        if (!$this->crypto->available()) {
            return;
        }
        $cipher = $this->crypto->encrypt('hunter2', 'device');
        // A different AAD context must not decrypt — prevents reusing a token
        // ciphertext as a password ciphertext.
        $this->assertNull($this->crypto->decrypt($cipher, 'totp'));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        if (!$this->crypto->available()) {
            return;
        }
        $cipher = $this->crypto->encrypt('secret', 'ctx');
        $tampered = substr($cipher, 0, -6) . 'AAAAAA';
        $this->assertNull($this->crypto->decrypt($tampered, 'ctx'));
    }

    public function testIsEncryptedDetection(): void
    {
        $this->assertTrue($this->crypto->isEncrypted('enc:v1:abcdef'));
        $this->assertFalse($this->crypto->isEncrypted('plaintext-password'));
        $this->assertFalse($this->crypto->isEncrypted(''));
    }

    public function testTokenHashingIsStableAndSalted(): void
    {
        $token = 'basip_' . bin2hex(random_bytes(16));
        $this->assertSame(Crypto::hashToken($token), Crypto::hashToken($token));
        $this->assertNotSame($token, Crypto::hashToken($token));
        // Prefixed so a future algorithm change stays unambiguous:
        // "hash:sha256:" (12) + 64 hex chars = 76, well inside the 128-char column.
        $this->assertStringContains('hash:sha256:', Crypto::hashToken($token));
        $this->assertSame(76, strlen(Crypto::hashToken($token)));
        $this->assertLessThan(128, strlen(Crypto::hashToken($token)));
    }

    public function testSignedPayloads(): void
    {
        $signed = $this->crypto->sign(['id' => 42, 'exp' => time() + 3600], 'guest-pass');
        $payload = $this->crypto->unsign($signed, 'guest-pass');
        $this->assertNotNull($payload);
        $this->assertSame(42, $payload['id']);

        // Wrong context or a tampered payload must not verify.
        $this->assertNull($this->crypto->unsign($signed, 'other-context'));
        $this->assertNull($this->crypto->unsign($signed . 'x', 'guest-pass'));
        $this->assertNull($this->crypto->unsign('garbage', 'guest-pass'));
    }

    public function testUuidV4Shape(): void
    {
        $uuid = Crypto::uuidv4();
        $this->assertMatches('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        $this->assertNotSame($uuid, Crypto::uuidv4());
    }

    public function testRandomTokenEntropy(): void
    {
        $a = $this->crypto->randomToken(24);
        $b = $this->crypto->randomToken(24);
        $this->assertNotSame($a, $b);
        $this->assertSame(48, strlen($a));       // 24 bytes → 48 hex chars
        $this->assertMatches('/^[0-9a-f]+$/', $a);
    }

    /* ─────────────────────────── TOTP (RFC 6238) ─────────────────────────── */

    public function testTotpMatchesRfc6238Vectors(): void
    {
        // The RFC uses the ASCII secret "12345678901234567890" (base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ).
        $secret = Totp::base32Encode('12345678901234567890');
        $totp = new Totp('BAS-IP', 8, 30, 'sha1', 1);

        $this->assertSame('94287082', $totp->code($secret, 59));
        $this->assertSame('07081804', $totp->code($secret, 1111111109));
        $this->assertSame('14050471', $totp->code($secret, 1111111111));
        $this->assertSame('89005924', $totp->code($secret, 1234567890));
        $this->assertSame('69279037', $totp->code($secret, 2000000000));
    }

    public function testTotpVerifyToleratesClockDrift(): void
    {
        $secret = Totp::generateSecret();
        $totp = new Totp('BAS-IP', 6, 30, 'sha1', 1);
        $now = 1700000010;

        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now), $now));
        // One period in the past/future must still verify (window = 1).
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now - 30), $now));
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now + 30), $now));
        // Two periods away must not.
        $this->assertFalse($totp->verify($secret, $totp->code($secret, $now - 120), $now));
    }

    public function testTotpRejectsGarbage(): void
    {
        $totp = new Totp('BAS-IP');
        $this->assertFalse($totp->verify('', '123456'));
        $this->assertFalse($totp->verify(Totp::generateSecret(), ''));
        $this->assertFalse($totp->verify(Totp::generateSecret(), 'abcdef'));
        $this->assertFalse($totp->verify(Totp::generateSecret(), '000000', 1700000010));
    }

    public function testTotpAcceptsSpacedInput(): void
    {
        $secret = Totp::generateSecret();
        $totp = new Totp('BAS-IP');
        $now = 1700000010;
        $code = $totp->code($secret, $now);
        $this->assertTrue($totp->verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $now));
    }

    public function testProvisioningUri(): void
    {
        $totp = new Totp('BAS-IP', 6, 30, 'sha1', 1);
        $uri = $totp->provisioningUri('SECRET234567', 'admin');
        $this->assertStringContains('otpauth://totp/', $uri);
        $this->assertStringContains('secret=SECRET234567', $uri);
        $this->assertStringContains('issuer=BAS-IP', $uri);
        $this->assertStringContains('digits=6', $uri);
        $this->assertStringContains('period=30', $uri);
    }

    public function testBase32RoundTrip(): void
    {
        foreach (['a', 'abc', '12345678901234567890', random_bytes(20)] as $value) {
            $value = is_string($value) ? $value : $value;
            $encoded = Totp::base32Encode((string) $value);
            $this->assertSame((string) $value, Totp::base32Decode($encoded));
        }
    }

    public function testGeneratedSecretIsBase32(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatches('/^[A-Z2-7]+$/', $secret);
        $this->assertSame(32, strlen($secret));      // 20 bytes → 32 base32 chars
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function testSecondsRemaining(): void
    {
        $totp = new Totp('BAS-IP', 6, 30);
        $this->assertSame(30, $totp->secondsRemaining(1700000010));
        $this->assertSame(15, $totp->secondsRemaining(1700000025));
        $this->assertSame(1, $totp->secondsRemaining(1700000039));
    }
}
