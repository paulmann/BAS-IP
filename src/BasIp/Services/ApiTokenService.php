<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\Permissions;
use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Support\Str;

/**
 * Machine API tokens.
 *
 * Only the SHA-256 hash is stored, so the token can be shown exactly once —
 * at creation time. Scopes are a subset of {@see Permissions::ALL} plus the
 * wildcard `*`.
 */
final class ApiTokenService
{
    /** @var array<int, string> */
    public const PRESET_SCOPES = [
        'gate.open', 'gate.close', 'gate.log', 'gate.status',
        'guest.create', 'guest.revoke', 'guest.view',
        'residents.view', 'billing.view', 'billing.payment.record',
        'notifications.send', 'system.health', '*',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->table('api_tokens')->orderBy('id', 'desc')->get();
    }

    /**
     * @param array<int, string> $scopes
     * @return array{id: int, token: string, prefix: string}
     */
    public function create(string $name, array $scopes = [], ?int $userId = null, ?string $expiresAt = null, ?User $actor = null, ?Request $request = null): array
    {
        $plain = 'basip_' . Str::random(40, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        $hash  = Crypto::hashToken($plain);
        $prefix = substr($plain, 0, 12);

        $scopes = array_values(array_intersect(self::PRESET_SCOPES, $scopes));
        if ($scopes === []) {
            $scopes = ['gate.open', 'gate.log'];
        }

        $id = $this->db->table('api_tokens')->insertGetId([
            'name'       => mb_substr(Str::squash($name) ?: 'Токен', 0, 120),
            'token_hash' => $hash,
            'prefix'     => $prefix,
            'scopes'     => (string) json_encode($scopes, JSON_UNESCAPED_UNICODE),
            'user_id'    => $userId,
            'is_active'  => 1,
            'expires_at' => $expiresAt,
            'created_at' => $this->clock->format(),
            'created_by' => $actor?->login ?? $actor?->displayName(),
        ]);

        $this->audit->record('api_token.create', $actor, [
            'subject_type' => 'api_token', 'subject_id' => (string) $id, 'subject_label' => $prefix . '…',
            'meta' => ['scopes' => $scopes, 'expires_at' => $expiresAt], 'is_security' => true,
        ], $request);

        return ['id' => $id, 'token' => $plain, 'prefix' => $prefix];
    }

    public function revoke(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $row = $this->db->table('api_tokens')->where('id', '=', $id)->first();
        if ($row === null) {
            return false;
        }
        $this->db->table('api_tokens')->where('id', '=', $id)->update(['is_active' => 0]);
        $this->audit->record('api_token.revoke', $actor, [
            'subject_type' => 'api_token', 'subject_id' => (string) $id,
            'subject_label' => (string) $row['name'], 'is_security' => true,
        ], $request);

        return true;
    }

    public function delete(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $ok = $this->db->table('api_tokens')->where('id', '=', $id)->delete() > 0;
        if ($ok) {
            $this->audit->info('api_token.delete', $actor, 'api_token', (string) $id, null, $request);
        }

        return $ok;
    }

    public function touch(int $id, ?string $ip): void
    {
        $this->db->table('api_tokens')->where('id', '=', $id)->update([
            'last_used_at' => $this->clock->format(),
            'last_used_ip' => $ip,
        ]);
    }

    /** Drop tokens past their expiry (cron). */
    public function purgeExpired(): int
    {
        return $this->db->table('api_tokens')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $this->clock->format())
            ->update(['is_active' => 0]);
    }

    public function fingerprint(string $token): string
    {
        return Crypto::fingerprint($token);
    }
}
