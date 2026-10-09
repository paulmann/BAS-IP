<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

use BasIp\Support\Plate;

/** One of possibly several cars belonging to a resident. */
final class Car extends Entity
{
    protected const BOOLS = ['is_active'];
    protected const INTS  = ['id', 'resident_id', 'year'];

    public int $id = 0;
    public int $residentId = 0;
    public string $plate = '';
    public string $plateNormalized = '';
    public ?string $brand = null;
    public ?string $model = null;
    public ?string $color = null;
    public ?int $year = null;
    public string $type = 'car';
    public ?string $rfidTag = null;
    public ?string $sticker = null;
    public bool $isActive = true;
    public ?string $validFrom = null;
    public ?string $validUntil = null;
    public ?string $notes = null;
    public ?string $createdAt = null;

    /** Resident name, filled when the car is loaded together with its owner. */
    public ?string $residentName = null;
    public ?string $apartment = null;

    protected function fill(array $row): void
    {
        $this->id             = (int) ($row['id'] ?? 0);
        $this->residentId     = (int) ($row['resident_id'] ?? 0);
        $this->plate          = self::str($row['plate'] ?? '');
        $this->plateNormalized = self::str($row['plate_normalized'] ?? '') ?: Plate::normalize($this->plate);
        $this->brand          = isset($row['brand']) && $row['brand'] !== '' ? (string) $row['brand'] : null;
        $this->model          = isset($row['model']) && $row['model'] !== '' ? (string) $row['model'] : null;
        $this->color          = isset($row['color']) && $row['color'] !== '' ? (string) $row['color'] : null;
        $this->year           = $row['year'] ?? null;
        $this->type           = (string) ($row['type'] ?? 'car');
        $this->rfidTag        = isset($row['rfid_tag']) && $row['rfid_tag'] !== '' ? (string) $row['rfid_tag'] : null;
        $this->sticker        = isset($row['sticker']) && $row['sticker'] !== '' ? (string) $row['sticker'] : null;
        $this->isActive       = (bool) ($row['is_active'] ?? true);
        $this->validFrom      = isset($row['valid_from']) ? (string) $row['valid_from'] : null;
        $this->validUntil     = isset($row['valid_until']) ? (string) $row['valid_until'] : null;
        $this->notes          = isset($row['notes']) ? (string) $row['notes'] : null;
        $this->createdAt      = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $this->residentName   = isset($row['resident_name']) ? (string) $row['resident_name'] : null;
        $this->apartment      = isset($row['apartment']) ? (string) $row['apartment'] : null;
    }

    public function label(): string
    {
        $parts = array_filter([$this->brand, $this->model, $this->color !== null ? $this->color : null]);

        return trim(implode(' ', $parts));
    }

    public function displayPlate(): string
    {
        return Plate::format($this->plate);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'car'        => 'Легковой',
            'suv'        => 'Внедорожник',
            'motorcycle' => 'Мотоцикл',
            'truck'      => 'Грузовой',
            'ev'         => 'Электромобиль',
            default      => $this->type,
        };
    }

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();
        if ($this->validUntil !== null && $this->validUntil !== '' && strtotime($this->validUntil) < $now) {
            return true;
        }
        if ($this->validFrom !== null && $this->validFrom !== '' && strtotime($this->validFrom) > $now) {
            return true;
        }

        return false;
    }

    public function isValidNow(?int $now = null): bool
    {
        return $this->isActive && !$this->isExpired($now);
    }
}
