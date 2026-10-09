<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

use BasIp\Support\Phone;
use BasIp\Support\Str;

/**
 * A resident ("житель") — the central record of the building database.
 *
 * Flags that drive everything else:
 *   is_active      — «включен/выключен». Only active residents take part in
 *                    billing splits, mass mailings and statistics.
 *   access_granted — «доступ». Whether the barrier opens for this person.
 *   is_billed      — participates in the maintenance cost split.
 */
final class Resident extends Entity
{
    protected const BOOLS = [
        'access_granted', 'is_active', 'is_billed', 'allow_guest_pass', 'notify_enabled',
    ];
    protected const INTS = ['id', 'apartment_no', 'floor', 'persons', 'created_by', 'updated_by', 'guest_passes_issued'];
    protected const FLOATS = ['area', 'share', 'balance'];
    protected const JSON = ['tags', 'meta'];

    public int $id = 0;
    public string $uuid = '';
    public ?string $externalId = null;

    public string $lastName = '';
    public string $firstName = '';
    public string $patronymic = '';
    public string $fullName = '';
    public ?string $searchIndex = null;

    public string $apartment = '';
    public ?int $apartmentNo = null;
    public ?string $building = null;
    public ?string $entrance = null;
    public ?int $floor = null;
    public float $area = 0.0;
    public int $persons = 1;
    public float $share = 1.0;
    public string $roleInHome = 'owner';

    public ?string $phone = null;
    public ?string $email = null;

    public bool $accessGranted = true;
    public bool $isActive = true;
    public bool $isBilled = true;
    public bool $allowGuestPass = true;
    public bool $notifyEnabled = true;

    public ?string $notes = null;
    /** @var array<int, string> */
    public array $tags = [];
    public float $balance = 0.0;
    public ?string $lastPaymentAt = null;
    public ?string $lastGateEventAt = null;
    public ?string $avatarPath = null;
    public int $guestPassesIssued = 0;
    /** @var array<string, mixed> */
    public array $meta = [];

    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    public ?string $archivedAt = null;
    public ?string $deletedAt = null;

    /* ── joined / computed (filled by the repository) ────────────────── */
    /** @var array<int, array<string, mixed>> */
    public array $contacts = [];
    /** @var array<int, array<string, mixed>> */
    public array $cars = [];
    public ?float $debt = null;
    public ?float $overdue = null;
    public ?int $invoicesPending = null;
    public ?string $lastInvoicePeriod = null;

    protected function fill(array $row): void
    {
        $this->id            = (int) ($row['id'] ?? 0);
        $this->uuid          = (string) ($row['uuid'] ?? '');
        $this->externalId    = isset($row['external_id']) ? (string) $row['external_id'] : null;

        $this->lastName      = self::str($row['last_name'] ?? '');
        $this->firstName     = self::str($row['first_name'] ?? '');
        $this->patronymic    = self::str($row['patronymic'] ?? '');
        $this->fullName      = self::str($row['full_name'] ?? '') ?: $this->composeName();
        $this->searchIndex   = isset($row['search_index']) ? (string) $row['search_index'] : null;

        $this->apartment     = self::str($row['apartment'] ?? '');
        $this->apartmentNo   = $row['apartment_no'] ?? null;
        $this->building      = isset($row['building']) && $row['building'] !== '' ? (string) $row['building'] : null;
        $this->entrance      = isset($row['entrance']) && $row['entrance'] !== '' ? (string) $row['entrance'] : null;
        $this->floor         = $row['floor'] ?? null;
        $this->area          = (float) ($row['area'] ?? 0);
        $this->persons       = max(1, (int) ($row['persons'] ?? 1));
        $this->share         = (float) ($row['share'] ?? 1);
        $this->roleInHome    = (string) ($row['role_in_home'] ?? 'owner');

        $this->phone         = isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null;
        $this->email         = isset($row['email']) && $row['email'] !== '' ? strtolower((string) $row['email']) : null;

        $this->accessGranted = (bool) ($row['access_granted'] ?? true);
        $this->isActive      = (bool) ($row['is_active'] ?? true);
        $this->isBilled      = (bool) ($row['is_billed'] ?? true);
        $this->allowGuestPass = (bool) ($row['allow_guest_pass'] ?? true);
        $this->notifyEnabled = (bool) ($row['notify_enabled'] ?? true);

        $this->notes         = isset($row['notes']) ? (string) $row['notes'] : null;
        $this->tags          = is_array($row['tags'] ?? null) ? array_values(array_map('strval', $row['tags'])) : [];
        $this->balance       = (float) ($row['balance'] ?? 0);
        $this->lastPaymentAt = isset($row['last_payment_at']) ? (string) $row['last_payment_at'] : null;
        $this->lastGateEventAt = isset($row['last_gate_event_at']) ? (string) $row['last_gate_event_at'] : null;
        $this->avatarPath    = isset($row['avatar_path']) ? (string) $row['avatar_path'] : null;
        $this->guestPassesIssued = (int) ($row['guest_passes_issued'] ?? 0);
        $this->meta          = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        $this->createdAt     = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $this->updatedAt     = isset($row['updated_at']) ? (string) $row['updated_at'] : null;
        $this->archivedAt    = isset($row['archived_at']) ? (string) $row['archived_at'] : null;
        $this->deletedAt     = isset($row['deleted_at']) ? (string) $row['deleted_at'] : null;
    }

    public function composeName(): string
    {
        return Str::squash(trim($this->lastName . ' ' . $this->firstName . ' ' . $this->patronymic));
    }

    public function displayName(): string
    {
        return $this->fullName !== '' ? $this->fullName : ($this->apartment !== '' ? 'Кв. ' . $this->apartment : 'Без имени');
    }

    public function shortName(): string
    {
        return Str::initials($this->displayName(), 2);
    }

    public function initials(): string
    {
        return Str::avatarInitials($this->displayName());
    }

    public function address(): string
    {
        $parts = array_filter([
            $this->building !== null ? 'корп. ' . $this->building : null,
            $this->apartment !== '' ? 'кв. ' . $this->apartment : null,
            $this->entrance !== null ? 'под. ' . $this->entrance : null,
        ]);

        return implode(', ', $parts) ?: '—';
    }

    public function formattedPhone(): string
    {
        return $this->phone === null ? '' : Phone::format($this->phone);
    }

    /** Every phone number, primary first. @return array<int, string> */
    public function allPhones(): array
    {
        $out = [];
        foreach ($this->contacts as $contact) {
            if (($contact['type'] ?? '') === 'phone' && !empty($contact['value'])) {
                $out[] = (string) $contact['value'];
            }
        }
        if ($this->phone !== null && !in_array($this->phone, $out, true)) {
            array_unshift($out, $this->phone);
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    public function allEmails(): array
    {
        $out = [];
        foreach ($this->contacts as $contact) {
            if (($contact['type'] ?? '') === 'email' && !empty($contact['value'])) {
                $out[] = strtolower((string) $contact['value']);
            }
        }
        if ($this->email !== null && !in_array($this->email, $out, true)) {
            array_unshift($out, $this->email);
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, array{type: string, value: string, label: ?string, notify: bool, primary: bool}> */
    public function notifyTargets(string $channel): array
    {
        $type = match ($channel) {
            'mail'     => 'email',
            'telegram' => 'telegram',
            'whatsapp' => 'whatsapp',
            'sms'      => 'phone',
            default    => $channel,
        };

        $targets = [];
        foreach ($this->contacts as $contact) {
            if (($contact['type'] ?? '') !== $type || empty($contact['value'])) {
                continue;
            }
            if (!Entity::toBool($contact['notify'] ?? true)) {
                continue;
            }
            $targets[] = [
                'type'    => (string) $contact['type'],
                'value'   => (string) $contact['value'],
                'label'   => isset($contact['label']) ? (string) $contact['label'] : null,
                'notify'  => true,
                'primary' => Entity::toBool($contact['is_primary'] ?? false),
            ];
        }

        // Fall back to the denormalised primary columns.
        if ($targets === []) {
            $fallback = match ($type) {
                'email' => $this->email,
                'phone' => $this->phone,
                default => null,
            };
            if ($fallback !== null && $fallback !== '') {
                $targets[] = ['type' => $type, 'value' => $fallback, 'label' => 'основной', 'notify' => true, 'primary' => true];
            }
        }

        return $targets;
    }

    public function hasDebt(): bool
    {
        return ($this->debt ?? 0.0) > 0.005 || $this->balance < -0.005;
    }

    public function canOpenGate(): bool
    {
        return $this->isActive && $this->accessGranted && $this->deletedAt === null;
    }

    public function participatesInBilling(): bool
    {
        return $this->isActive && $this->isBilled && $this->deletedAt === null;
    }

    public function roleLabel(): string
    {
        return match ($this->roleInHome) {
            'owner'    => 'Собственник',
            'tenant'   => 'Арендатор',
            'relative' => 'Родственник',
            'manager'  => 'Управляющий',
            default    => $this->roleInHome,
        };
    }

    /** Allocation weight for the chosen strategy. */
    public function billingWeight(string $allocation): float
    {
        return match ($allocation) {
            'by_persons' => (float) max(1, $this->persons),
            'by_area'    => max(0.0, $this->area),
            'by_share'   => max(0.0, $this->share),
            default      => 1.0,
        };
    }

    /** @return array<string, mixed> */
    /**
     * Magic read access to snake_case columns that are not promoted to typed
     * properties (e.g. `guest_passes_issued`), so views never hit an
     * "undefined property" warning.
     */
    public function __get(string $name): mixed
    {
        return $this->raw[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->raw[$name]);
    }

    public function toArray(): array
    {
        return $this->raw + [
            'display_name' => $this->displayName(),
            'address'      => $this->address(),
            'can_open_gate' => $this->canOpenGate(),
            'billed'       => $this->participatesInBilling(),
            'weight'       => $this->billingWeight('equal'),
            'phones'       => $this->allPhones(),
            'emails'       => $this->allEmails(),
            'cars'         => array_map(static fn (array $c): string => trim(($c['plate'] ?? '') . ' ' . ($c['brand'] ?? '') . ' ' . ($c['model'] ?? '')), $this->cars),
        ];
    }
}
