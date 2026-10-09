<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

use BasIp\Support\Phone;

/**
 * A single contact channel of a resident.
 *
 * `type` drives which notification driver can use it:
 * phone → sms, email → mail, telegram/whatsapp → messenger, sip → panel call.
 */
final class Contact extends Entity
{
    public const TYPES = ['phone', 'email', 'telegram', 'whatsapp', 'viber', 'sip', 'other'];

    protected const BOOLS = ['is_primary', 'notify', 'verified'];
    protected const INTS  = ['id', 'resident_id'];

    public int $id = 0;
    public int $residentId = 0;
    public string $type = 'phone';
    public string $value = '';
    public ?string $valueNormalized = null;
    public ?string $label = null;
    public bool $isPrimary = false;
    public bool $notify = true;
    public bool $verified = false;
    public ?string $createdAt = null;

    protected function fill(array $row): void
    {
        $this->id          = (int) ($row['id'] ?? 0);
        $this->residentId  = (int) ($row['resident_id'] ?? 0);
        $this->type        = (string) ($row['type'] ?? 'phone');
        $this->value       = self::str($row['value'] ?? '');
        $this->valueNormalized = isset($row['value_normalized']) ? (string) $row['value_normalized'] : null;
        $this->label       = isset($row['label']) && $row['label'] !== '' ? (string) $row['label'] : null;
        $this->isPrimary   = (bool) ($row['is_primary'] ?? false);
        $this->notify      = (bool) ($row['notify'] ?? true);
        $this->verified    = (bool) ($row['verified'] ?? false);
        $this->createdAt   = isset($row['created_at']) ? (string) $row['created_at'] : null;
    }

    public static function normalizeValue(string $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            'phone'    => Phone::normalize($value),
            'email'    => strtolower($value),
            'telegram' => self::normalizeTelegram($value),
            default    => $value,
        };
    }

    /** Accepts @name, t.me/name, a chat id or a plain username. */
    public static function normalizeTelegram(string $value): string
    {
        $value = trim($value);
        if (preg_match('~t\.me/([A-Za-z0-9_]{4,32})~i', $value, $m)) {
            return '@' . $m[1];
        }
        if (preg_match('/^-?\d{5,20}$/', $value)) {
            return $value;                    // numeric chat id
        }

        return '@' . ltrim($value, '@');
    }

    public function display(): string
    {
        return match ($this->type) {
            'phone' => Phone::format($this->value),
            default => $this->value,
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'phone'    => 'Телефон',
            'email'    => 'E-mail',
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
            'viber'    => 'Viber',
            'sip'      => 'SIP',
            default    => $this->type,
        };
    }

    public function icon(): string
    {
        return match ($this->type) {
            'phone'    => 'phone',
            'email'    => 'mail',
            'telegram' => 'send',
            'whatsapp' => 'message-circle',
            'viber'    => 'message-square',
            'sip'      => 'phone-call',
            default    => 'at-sign',
        };
    }

    public function href(): ?string
    {
        return match ($this->type) {
            'phone'    => 'tel:' . Phone::tel($this->value),
            'email'    => 'mailto:' . $this->value,
            'telegram' => str_starts_with($this->value, '@') ? 'https://t.me/' . substr($this->value, 1) : null,
            'whatsapp' => 'https://wa.me/' . preg_replace('/\D/', '', Phone::normalize($this->value)),
            default    => null,
        };
    }
}
