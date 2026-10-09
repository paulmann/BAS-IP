<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

/** A physical panel / barrier controller. Several gates per installation are supported. */
final class Device extends Entity
{
    protected const BOOLS = ['is_default', 'is_active'];
    protected const INTS  = ['id', 'channel', 'error_count'];
    protected const JSON  = ['options', 'last_status'];

    public int $id = 0;
    public string $name = '';
    public string $slug = '';
    public string $driver = 'basip';
    public ?string $baseUrl = null;
    public ?string $login = null;
    public ?string $passwordEnc = null;
    public int $channel = 1;
    public ?string $location = null;
    public bool $isDefault = false;
    public bool $isActive = true;
    /** @var array<string, mixed> */
    public array $options = [];
    public ?string $lastSeenAt = null;
    /** @var array<string, mixed> */
    public array $lastStatus = [];
    public int $errorCount = 0;
    public ?string $notes = null;
    public ?string $createdAt = null;

    protected function fill(array $row): void
    {
        $this->id          = (int) ($row['id'] ?? 0);
        $this->name        = self::str($row['name'] ?? '');
        $this->slug        = self::str($row['slug'] ?? '');
        $this->driver      = (string) ($row['driver'] ?? 'basip');
        $this->baseUrl     = isset($row['base_url']) && $row['base_url'] !== '' ? (string) $row['base_url'] : null;
        $this->login       = isset($row['login']) && $row['login'] !== '' ? (string) $row['login'] : null;
        $this->passwordEnc = isset($row['password_enc']) && $row['password_enc'] !== '' ? (string) $row['password_enc'] : null;
        $this->channel     = max(1, (int) ($row['channel'] ?? 1));
        $this->location    = isset($row['location']) && $row['location'] !== '' ? (string) $row['location'] : null;
        $this->isDefault   = (bool) ($row['is_default'] ?? false);
        $this->isActive    = (bool) ($row['is_active'] ?? true);
        $this->options     = is_array($row['options'] ?? null) ? $row['options'] : [];
        $this->lastSeenAt  = isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null;
        $this->lastStatus  = is_array($row['last_status'] ?? null) ? $row['last_status'] : [];
        $this->errorCount  = (int) ($row['error_count'] ?? 0);
        $this->notes       = isset($row['notes']) ? (string) $row['notes'] : null;
        $this->createdAt   = isset($row['created_at']) ? (string) $row['created_at'] : null;
    }

    public function label(): string
    {
        return $this->name !== '' ? $this->name : ('Устройство #' . $this->id);
    }

    public function host(): string
    {
        return (string) (parse_url((string) $this->baseUrl, PHP_URL_HOST) ?: '—');
    }

    public function isHealthy(): bool
    {
        return $this->errorCount < 3;
    }

    public function driverLabel(): string
    {
        return match ($this->driver) {
            'basip'      => 'BAS-IP Camdroid (HTTP API v0)',
            'http-relay' => 'Универсальное HTTP-реле',
            'dryrun'     => 'Эмулятор (без железа)',
            default      => $this->driver,
        };
    }
}
