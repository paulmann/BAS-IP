<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

use BasIp\Support\Plate;

/**
 * A guest access pass (12 hours by default).
 *
 * The one-time link is stored only as a SHA-256 hash — a database leak does not
 * hand out working keys to the yard.
 */
final class GuestPass extends Entity
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_USED    = 'used';

    protected const BOOLS = ['notify_on_use'];
    protected const INTS  = ['id', 'resident_id', 'created_by', 'max_guests', 'ttl_hours', 'max_uses', 'used_count', 'revoked_by'];
    protected const JSON  = ['meta'];

    public int $id = 0;
    public string $uuid = '';
    public string $code = '';
    public ?string $pin = null;
    public ?int $residentId = null;
    public ?int $createdBy = null;
    public string $createdByType = 'resident';
    public ?string $guestName = null;
    public ?string $guestPhone = null;
    public ?string $carPlate = null;
    public ?string $carPlateNormalized = null;
    public ?string $purpose = null;
    public int $maxGuests = 1;
    public ?string $validFrom = null;
    public ?string $validUntil = null;
    public int $ttlHours = 12;
    public int $maxUses = 0;
    public int $usedCount = 0;
    public ?string $lastUsedAt = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $revokedAt = null;
    public ?int $revokedBy = null;
    public ?string $revokeReason = null;
    public ?string $ipCreated = null;
    public ?string $ipLastUsed = null;
    public bool $notifyOnUse = false;
    /** @var array<string, mixed> */
    public array $meta = [];
    public ?string $createdAt = null;

    /* joined / runtime */
    public ?string $residentName = null;
    public ?string $apartment = null;
    public ?string $link = null;

    protected function fill(array $row): void
    {
        $this->id          = (int) ($row['id'] ?? 0);
        $this->uuid        = (string) ($row['uuid'] ?? '');
        $this->code        = (string) ($row['code'] ?? '');
        $this->pin         = isset($row['pin']) && $row['pin'] !== '' ? (string) $row['pin'] : null;
        $this->residentId  = $row['resident_id'] ?? null;
        $this->createdBy   = $row['created_by'] ?? null;
        $this->createdByType = (string) ($row['created_by_type'] ?? 'resident');
        $this->guestName   = isset($row['guest_name']) && $row['guest_name'] !== '' ? (string) $row['guest_name'] : null;
        $this->guestPhone  = isset($row['guest_phone']) && $row['guest_phone'] !== '' ? (string) $row['guest_phone'] : null;
        $this->carPlate    = isset($row['car_plate']) && $row['car_plate'] !== '' ? (string) $row['car_plate'] : null;
        $this->carPlateNormalized = isset($row['car_plate_normalized']) ? (string) $row['car_plate_normalized'] : null;
        $this->purpose     = isset($row['purpose']) && $row['purpose'] !== '' ? (string) $row['purpose'] : null;
        $this->maxGuests   = max(1, (int) ($row['max_guests'] ?? 1));
        $this->validFrom   = isset($row['valid_from']) ? (string) $row['valid_from'] : null;
        $this->validUntil  = isset($row['valid_until']) ? (string) $row['valid_until'] : null;
        $this->ttlHours    = (int) ($row['ttl_hours'] ?? 12);
        $this->maxUses     = (int) ($row['max_uses'] ?? 0);
        $this->usedCount   = (int) ($row['used_count'] ?? 0);
        $this->lastUsedAt  = isset($row['last_used_at']) ? (string) $row['last_used_at'] : null;
        $this->status      = (string) ($row['status'] ?? self::STATUS_ACTIVE);
        $this->revokedAt   = isset($row['revoked_at']) ? (string) $row['revoked_at'] : null;
        $this->revokedBy   = $row['revoked_by'] ?? null;
        $this->revokeReason = isset($row['revoke_reason']) ? (string) $row['revoke_reason'] : null;
        $this->ipCreated   = isset($row['ip_created']) ? (string) $row['ip_created'] : null;
        $this->ipLastUsed  = isset($row['ip_last_used']) ? (string) $row['ip_last_used'] : null;
        $this->notifyOnUse = (bool) ($row['notify_on_use'] ?? false);
        $this->meta        = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $this->createdAt   = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $this->residentName = isset($row['resident_name']) ? (string) $row['resident_name'] : null;
        $this->apartment   = isset($row['apartment']) ? (string) $row['apartment'] : null;
    }

    public function isActive(?int $now = null): bool
    {
        $now ??= time();
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->validFrom !== null && $this->validFrom !== '' && strtotime($this->validFrom) > $now) {
            return false;
        }
        if ($this->validUntil !== null && $this->validUntil !== '' && strtotime($this->validUntil) < $now) {
            return false;
        }
        if ($this->maxUses > 0 && $this->usedCount >= $this->maxUses) {
            return false;
        }

        return true;
    }

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();

        return $this->validUntil !== null && $this->validUntil !== '' && strtotime($this->validUntil) < $now;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function statusLabel(?int $now = null): string
    {
        return match (true) {
            $this->isRevoked()          => 'Отозван',
            $this->isExpired($now)      => 'Истёк',
            $this->isActive($now)       => 'Активен',
            $this->status === self::STATUS_USED => 'Использован',
            default                     => $this->status,
        };
    }

    public function statusTone(?int $now = null): string
    {
        return match (true) {
            $this->isRevoked()     => 'muted',
            $this->isExpired($now) => 'muted',
            $this->isActive($now)  => 'success',
            default                => 'secondary',
        };
    }

    /** Human countdown, e.g. «осталось 7 ч 12 мин». */
    public function timeLeft(?int $now = null): string
    {
        if ($this->validUntil === null) {
            return '—';
        }
        $now ??= time();
        $diff = strtotime($this->validUntil) - $now;
        if ($diff <= 0) {
            return 'истёк';
        }
        $hours = intdiv($diff, 3600);
        $minutes = intdiv($diff % 3600, 60);

        return $hours > 0 ? sprintf('осталось %d ч %02d мин', $hours, $minutes) : sprintf('осталось %d мин', $minutes);
    }

    public function percentElapsed(?int $now = null): float
    {
        if ($this->validFrom === null || $this->validUntil === null) {
            return 0.0;
        }
        $now ??= time();
        $start = strtotime($this->validFrom);
        $end   = strtotime($this->validUntil);
        if ($end <= $start) {
            return 100.0;
        }

        return round(min(100.0, max(0.0, (($now - $start) / ($end - $start)) * 100)), 1);
    }

    public function displayPlate(): string
    {
        return $this->carPlate === null ? '' : Plate::format($this->carPlate);
    }

    public function usesLabel(): string
    {
        if ($this->maxUses <= 0) {
            return $this->usedCount . ' исп.';
        }

        return $this->usedCount . ' / ' . $this->maxUses;
    }
}
