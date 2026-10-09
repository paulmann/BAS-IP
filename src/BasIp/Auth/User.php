<?php

declare(strict_types=1);

namespace BasIp\Auth;

use BasIp\Support\Str;

/**
 * Authenticated account (admin, operator or resident).
 *
 * A plain DTO over a `users` row — no ORM magic, explicit casts, and secrets
 * (`password_hash`, `totp_secret`, `recovery_codes`) are never serialised.
 */
final class User implements \JsonSerializable
{
    /** @param array<string, mixed> $preferences */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $login,
        public readonly ?string $email,
        public readonly string $fullName,
        public readonly string $role,
        public readonly ?int $residentId,
        public readonly bool $isActive,
        public readonly bool $mustChangePassword,
        public readonly bool $totpEnabled,
        public readonly string $passwordHash,
        public readonly ?string $totpSecret,
        public readonly ?string $rememberToken,
        public readonly int $failedAttempts,
        public readonly ?string $lockedUntil,
        public readonly ?string $lastLoginAt,
        public readonly ?string $lastLoginIp,
        public readonly ?string $passwordChangedAt,
        public readonly ?string $locale,
        public readonly array $preferences = [],
        public readonly ?string $createdAt = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $preferences = [];
        if (isset($row['preferences']) && is_string($row['preferences']) && $row['preferences'] !== '') {
            $decoded = json_decode($row['preferences'], true);
            if (is_array($decoded)) {
                $preferences = $decoded;
            }
        }

        return new self(
            id: (int) ($row['id'] ?? 0),
            uuid: (string) ($row['uuid'] ?? ''),
            login: (string) ($row['login'] ?? ''),
            email: isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
            fullName: (string) ($row['full_name'] ?? ''),
            role: (string) ($row['role'] ?? 'resident'),
            residentId: isset($row['resident_id']) && $row['resident_id'] !== null ? (int) $row['resident_id'] : null,
            isActive: (bool) ($row['is_active'] ?? false),
            mustChangePassword: (bool) ($row['must_change_password'] ?? false),
            totpEnabled: (bool) ($row['totp_enabled'] ?? false),
            passwordHash: (string) ($row['password_hash'] ?? ''),
            totpSecret: isset($row['totp_secret']) && $row['totp_secret'] !== '' ? (string) $row['totp_secret'] : null,
            rememberToken: isset($row['remember_token']) && $row['remember_token'] !== '' ? (string) $row['remember_token'] : null,
            failedAttempts: (int) ($row['failed_attempts'] ?? 0),
            lockedUntil: isset($row['locked_until']) ? (string) $row['locked_until'] : null,
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            lastLoginIp: isset($row['last_login_ip']) ? (string) $row['last_login_ip'] : null,
            passwordChangedAt: isset($row['password_changed_at']) ? (string) $row['password_changed_at'] : null,
            locale: isset($row['locale']) && $row['locale'] !== '' ? (string) $row['locale'] : null,
            preferences: $preferences,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    public function isAdmin(): bool     { return $this->role === 'admin'; }
    public function isOperator(): bool  { return $this->role === 'operator'; }
    public function isResident(): bool  { return $this->role === 'resident'; }
    public function isStaff(): bool     { return $this->isAdmin() || $this->isOperator(); }

    public function can(string $permission): bool
    {
        return Permissions::can($this->role, $permission);
    }

    /** @param array<int, string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function isLocked(?int $now = null): bool
    {
        if ($this->lockedUntil === null || $this->lockedUntil === '') {
            return false;
        }

        return strtotime($this->lockedUntil) > ($now ?? time());
    }

    public function displayName(): string
    {
        return $this->fullName !== '' ? $this->fullName : $this->login;
    }

    public function initials(): string
    {
        return Str::avatarInitials($this->displayName());
    }

    /** "Иванов Иван Иванович" → "Иванов И.И." — used in mailings and tables. */
    public function shortName(): string
    {
        return Str::initials($this->displayName(), 2);
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'admin'    => 'Администратор',
            'operator' => 'Диспетчер',
            'resident' => 'Житель',
            default    => $this->role,
        };
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return $this->preferences[$key] ?? $default;
    }

    public function theme(): string
    {
        $theme = (string) $this->preference('theme', 'system');

        return in_array($theme, ['light', 'dark', 'system'], true) ? $theme : 'system';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'login' => $this->login,
            'email' => $this->email,
            'full_name' => $this->fullName,
            'role' => $this->role,
            'resident_id' => $this->residentId,
            'is_active' => $this->isActive,
            'totp_enabled' => $this->totpEnabled,
            'last_login_at' => $this->lastLoginAt,
        ];
    }
}
