<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

/**
 * One row of the immutable barrier log: who opened it, when, and by which
 * kind of access (own / guest / manual / API / plate …).
 */
final class GateEvent extends Entity
{
    public const ACCESS_OWN       = 'own';
    public const ACCESS_GUEST     = 'guest';
    public const ACCESS_MANUAL    = 'manual';
    public const ACCESS_REMOTE    = 'remote';
    public const ACCESS_PLATE     = 'plate';
    public const ACCESS_RFID      = 'rfid';
    public const ACCESS_SCHEDULED = 'scheduled';
    public const ACCESS_EMERGENCY = 'emergency';
    public const ACCESS_API       = 'api';
    public const ACCESS_TEST      = 'test';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_DENIED  = 'denied';
    public const RESULT_ERROR   = 'error';

    protected const INTS = ['id', 'device_id', 'channel', 'resident_id', 'user_id', 'guest_pass_id', 'duration_ms'];
    protected const JSON = ['meta'];

    public int $id = 0;
    public string $uuid = '';
    public ?string $occurredAt = null;
    public ?int $deviceId = null;
    public int $channel = 1;
    public string $actorType = 'system';
    public ?int $residentId = null;
    public ?int $userId = null;
    public ?int $guestPassId = null;
    public ?string $actorName = null;
    public ?string $apartment = null;
    public string $accessType = self::ACCESS_OWN;
    public string $action = 'open';
    public string $source = 'web';
    public string $result = self::RESULT_SUCCESS;
    public ?string $reason = null;
    public ?string $plate = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?string $requestId = null;
    public ?int $durationMs = null;
    /** @var array<string, mixed> */
    public array $meta = [];

    /* joined */
    public ?string $deviceName = null;
    public ?string $guestCode = null;
    public ?string $residentName = null;

    protected function fill(array $row): void
    {
        $this->id          = (int) ($row['id'] ?? 0);
        $this->uuid        = (string) ($row['uuid'] ?? '');
        $this->occurredAt  = isset($row['occurred_at']) ? (string) $row['occurred_at'] : null;
        $this->deviceId    = $row['device_id'] ?? null;
        $this->channel     = (int) ($row['channel'] ?? 1);
        $this->actorType   = (string) ($row['actor_type'] ?? 'system');
        $this->residentId  = $row['resident_id'] ?? null;
        $this->userId      = $row['user_id'] ?? null;
        $this->guestPassId = $row['guest_pass_id'] ?? null;
        $this->actorName   = isset($row['actor_name']) ? (string) $row['actor_name'] : null;
        $this->apartment   = isset($row['apartment']) ? (string) $row['apartment'] : null;
        $this->accessType  = (string) ($row['access_type'] ?? self::ACCESS_OWN);
        $this->action      = (string) ($row['action'] ?? 'open');
        $this->source      = (string) ($row['source'] ?? 'web');
        $this->result      = (string) ($row['result'] ?? self::RESULT_SUCCESS);
        $this->reason      = isset($row['reason']) ? (string) $row['reason'] : null;
        $this->plate       = isset($row['plate']) ? (string) $row['plate'] : null;
        $this->ip          = isset($row['ip']) ? (string) $row['ip'] : null;
        $this->userAgent   = isset($row['user_agent']) ? (string) $row['user_agent'] : null;
        $this->requestId   = isset($row['request_id']) ? (string) $row['request_id'] : null;
        $this->durationMs  = $row['duration_ms'] ?? null;
        $this->meta        = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $this->deviceName  = isset($row['device_name']) ? (string) $row['device_name'] : null;
        $this->guestCode   = isset($row['guest_code']) ? (string) $row['guest_code'] : null;
        $this->residentName = isset($row['resident_name']) ? (string) $row['resident_name'] : null;
    }

    public function accessLabel(): string
    {
        return match ($this->accessType) {
            self::ACCESS_OWN       => 'Свой доступ',
            self::ACCESS_GUEST     => 'Гостевой',
            self::ACCESS_MANUAL    => 'Вручную (кнопка)',
            self::ACCESS_REMOTE    => 'Удалённо',
            self::ACCESS_PLATE     => 'По номеру авто',
            self::ACCESS_RFID      => 'По карте/метке',
            self::ACCESS_SCHEDULED => 'По расписанию',
            self::ACCESS_EMERGENCY => 'Аварийный',
            self::ACCESS_API       => 'Через API',
            self::ACCESS_TEST      => 'Тест',
            default                => $this->accessType,
        };
    }

    public function accessTone(): string
    {
        return match ($this->accessType) {
            self::ACCESS_OWN       => 'info',
            self::ACCESS_GUEST     => 'warning',
            self::ACCESS_EMERGENCY => 'destructive',
            self::ACCESS_TEST      => 'muted',
            default                => 'secondary',
        };
    }

    public function resultLabel(): string
    {
        return match ($this->result) {
            self::RESULT_SUCCESS => 'Успешно',
            self::RESULT_DENIED  => 'Отказано',
            self::RESULT_ERROR   => 'Ошибка',
            default              => $this->result,
        };
    }

    public function resultTone(): string
    {
        return match ($this->result) {
            self::RESULT_SUCCESS => 'success',
            self::RESULT_DENIED  => 'warning',
            self::RESULT_ERROR   => 'destructive',
            default              => 'muted',
        };
    }

    public function actorLabel(): string
    {
        $who = match ($this->actorType) {
            'resident' => $this->residentName ?? $this->actorName ?? 'Житель',
            'guest'    => 'Гость' . ($this->guestCode !== null ? ' (' . $this->guestCode . ')' : ''),
            'admin'    => 'Администратор: ' . ($this->actorName ?? '?'),
            'operator' => 'Диспетчер: ' . ($this->actorName ?? '?'),
            'api'      => 'API: ' . ($this->actorName ?? 'токен'),
            'panel'    => 'Панель BAS-IP',
            'schedule' => 'Расписание',
            default    => $this->actorName ?? 'Система',
        };

        return $this->apartment !== null && $this->apartment !== '' && !str_contains($who, 'кв.')
            ? $who . ' · кв. ' . $this->apartment
            : $who;
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'web'      => 'Веб-панель',
            'cabinet'  => 'Личный кабинет',
            'admin'    => 'Админка',
            'api'      => 'API',
            'cli'      => 'CLI',
            'telegram' => 'Telegram',
            'panel'    => 'Панель',
            'cron'     => 'Планировщик',
            default    => $this->source,
        };
    }

    public function isSuccess(): bool { return $this->result === self::RESULT_SUCCESS; }
    public function isGuest(): bool  { return $this->accessType === self::ACCESS_GUEST; }
    public function isOwn(): bool    { return $this->accessType === self::ACCESS_OWN; }

    public function when(string $format = 'd.m.Y H:i:s'): string
    {
        if ($this->occurredAt === null) {
            return '—';
        }
        $ts = strtotime($this->occurredAt);

        return $ts === false ? '—' : date($format, $ts);
    }
}
