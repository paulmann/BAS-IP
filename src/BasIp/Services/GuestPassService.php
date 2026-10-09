<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Domain\Model\GateEvent;
use BasIp\Domain\Model\GuestPass;
use BasIp\Domain\Model\Resident;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Notify\Message;
use BasIp\Notify\NotificationService;
use BasIp\Support\Paginator;
use BasIp\Support\Plate;
use BasIp\Support\Phone;
use BasIp\Support\Str;
use Throwable;

/**
 * Guest access passes (12 hours by default).
 *
 * A pass carries three interchangeable credentials so it works whatever the
 * guest has in hand:
 *   • a **signed one-time link** `/guest/<token>` — tap on a phone, press «Открыть»;
 *   • a human-readable **code** `7K4M-Q2XD` — dictate it over the phone;
 *   • an optional short **PIN** — for panels with a keypad.
 *
 * Only the SHA-256 hash of the link token is stored, so a database leak does
 * not produce working keys. Every issue, use and revocation is written to the
 * gate log and the audit trail.
 */
final class GuestPassService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly ResidentRepository $residents,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notify,
    ) {
    }

    /**
     * Issue a guest pass.
     *
     * @param array<string, mixed> $input
     *   resident_id, ttl_hours, guest_name, guest_phone, car_plate, purpose,
     *   max_uses, valid_from, notify, notify_guest, link_base
     * @return array{ok: bool, pass?: GuestPass, link?: string, token?: string, code?: string, pin?: string, error?: string}
     */
    public function create(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $residentId = (int) ($input['resident_id'] ?? ($actor?->residentId ?? 0));
        $resident = $residentId > 0 ? $this->residents->findById($residentId) : null;

        if ($resident === null) {
            return ['ok' => false, 'error' => 'Житель не найден.'];
        }
        if (!$resident->isActive) {
            return ['ok' => false, 'error' => 'Нельзя создать гостевой доступ для отключённого жителя.'];
        }
        if ($actor !== null && $actor->isResident() && !$resident->allowGuestPass) {
            return ['ok' => false, 'error' => 'Самостоятельная выдача гостевых доступов отключена для этого жителя.'];
        }

        $ttl = (int) ($input['ttl_hours'] ?? $this->settings->int('guest.default_ttl_hours', $this->config->int('guest.default_ttl_hours', 12)));
        $maxTtl = $this->config->int('guest.max_ttl_hours', 72);
        if ($ttl <= 0) {
            $ttl = 12;
        }
        // Residents may not exceed the configured maximum; staff can.
        if ($actor === null || !$actor->isStaff()) {
            $ttl = min($ttl, $maxTtl);
        }

        $limit = $this->settings->int('guest.max_per_resident', $this->config->int('guest.max_active_per_resident', 5));
        if ($limit > 0 && $this->countActiveFor($residentId) >= $limit) {
            return ['ok' => false, 'error' => sprintf('Достигнут лимит активных гостевых доступов (%d). Отозвите один из существующих.', $limit)];
        }

        $perDay = $this->config->int('guest.rate_limit_per_resident_day', 10);
        if ($perDay > 0 && $this->countCreatedToday($residentId) >= $perDay) {
            return ['ok' => false, 'error' => sprintf('Превышен суточный лимит создания гостевых доступов (%d).', $perDay)];
        }

        $plate = Str::squash((string) ($input['car_plate'] ?? ''));
        if ($this->settings->bool('guest.require_plate', $this->config->bool('guest.require_plate', false)) && $plate === '') {
            return ['ok' => false, 'error' => 'Укажите номер автомобиля гостя.'];
        }

        $now = $this->clock->now();
        $validFrom = $this->parseTime($input['valid_from'] ?? null) ?? $now;
        $validUntil = $validFrom + $ttl * 3600;

        $token = $this->crypto->randomToken(24);
        $code  = $this->uniqueCode();
        $pin   = $this->config->bool('guest.pin_fallback', true) ? $this->uniquePin() : null;

        $row = [
            'uuid'        => Crypto::uuidv4(),
            'code'        => $code,
            'token_hash'  => Crypto::hashToken($token),
            'pin'         => $pin,
            'resident_id' => $residentId,
            'created_by'  => $actor?->id ?? $residentId,
            'created_by_type' => $actor !== null && $actor->isStaff() ? 'staff' : 'resident',
            'guest_name'  => $this->clean($input['guest_name'] ?? null, 190),
            'guest_phone' => !empty($input['guest_phone']) ? Phone::normalize((string) $input['guest_phone']) : null,
            'car_plate'   => $plate !== '' ? mb_substr($plate, 0, 32) : null,
            'car_plate_normalized' => $plate !== '' ? Plate::normalize($plate) : null,
            'purpose'     => $this->clean($input['purpose'] ?? null, 190),
            'max_guests'  => max(1, (int) ($input['max_guests'] ?? 1)),
            'valid_from'  => $this->clock->format('Y-m-d H:i:s', $validFrom),
            'valid_until' => $this->clock->format('Y-m-d H:i:s', $validUntil),
            'ttl_hours'   => $ttl,
            'max_uses'    => max(0, (int) ($input['max_uses'] ?? $this->config->int('guest.max_uses', 0))),
            'used_count'  => 0,
            'status'      => GuestPass::STATUS_ACTIVE,
            'ip_created'  => $request?->ip($this->config),
            'notify_on_use' => Str::toBoolSafe($input['notify_on_use'] ?? false) ? 1 : 0,
            'meta'        => null,
            'created_at'  => $this->clock->format(),
            'updated_at'  => $this->clock->format(),
        ];

        try {
            $id = $this->db->table('guest_passes')->insertGetId($row);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось сохранить гостевой доступ: ' . $e->getMessage()];
        }

        $this->db->table('residents')->where('id', '=', $residentId)->update([
            'guest_passes_issued' => (int) $this->db->table('residents')->where('id', '=', $residentId)->value('guest_passes_issued', 0) + 1,
        ]);

        $pass = $this->find($id);
        $link = $this->linkFor($token, $input['link_base'] ?? null);

        $this->audit->record('guest.create', $actor, [
            'subject_type' => 'guest_pass', 'subject_id' => (string) $id, 'subject_label' => $code,
            'meta' => [
                'resident_id' => $residentId, 'resident' => $resident->displayName(),
                'ttl_hours' => $ttl, 'valid_until' => $row['valid_until'],
                'guest_name' => $row['guest_name'], 'car_plate' => $row['car_plate'],
                'purpose' => $row['purpose'], 'created_by_type' => $row['created_by_type'],
            ],
        ], $request);

        // Log the issuance into the gate log as well — the task requires every
        // guest-access event to be traceable.
        $this->writeGuestLog($pass, $resident, 'issued', $request, $actor);

        if (Str::toBoolSafe($input['notify'] ?? true)) {
            $this->notifyResident($resident, $pass, $link);
        }
        if (Str::toBoolSafe($input['notify_guest'] ?? false) && $row['guest_phone'] !== null) {
            $this->notifyGuest($pass, $link);
        }

        return ['ok' => true, 'pass' => $pass, 'link' => $link, 'token' => $token, 'code' => $code, 'pin' => $pin, 'id' => $id];
    }

    public function find(int $id): ?GuestPass
    {
        $row = $this->db->table('guest_passes g')
            ->select('g.*', 'r.full_name AS resident_name', 'r.apartment')
            ->leftJoin('residents r', 'r.id', '=', 'g.resident_id')
            ->where('g.id', '=', $id)
            ->first();

        return $row === null ? null : GuestPass::fromRow($row);
    }

    /** Resolve a one-time link token to a pass. */
    public function findByToken(string $token): ?GuestPass
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $hash = Crypto::hashToken($token);
        $row = $this->db->table('guest_passes g')
            ->select('g.*', 'r.full_name AS resident_name', 'r.apartment', 'r.is_active AS resident_active')
            ->leftJoin('residents r', 'r.id', '=', 'g.resident_id')
            ->where('g.token_hash', '=', $hash)
            ->first();

        if ($row === null) {
            // Also accept a signed payload (used when `guest.signed_link` is on).
            $payload = $this->crypto->unsign($token, 'guest-pass');
            if (is_array($payload) && isset($payload['id'])) {
                return $this->find((int) $payload['id']);
            }

            return null;
        }

        return GuestPass::fromRow($row);
    }

    /**
     * Resolve a pass from a human-typed code.
     *
     * Tolerant on purpose — this is read aloud over the phone or typed on a
     * keypad: case is ignored, separators are dropped, and both the dashed
     * (`7K4M-Q2XD`) and compact (`7K4MQ2XD`) spellings match. A PIN works too.
     */
    public function findByCode(string $code): ?GuestPass
    {
        $compact = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        if ($compact === '') {
            return null;
        }

        // Candidate spellings stored in the database.
        $candidates = [$compact];
        if (strlen($compact) === 8) {
            $candidates[] = substr($compact, 0, 4) . '-' . substr($compact, 4);
        } elseif (str_contains($code, '-') && preg_match('/^([A-Z0-9]+)-([A-Z0-9]+)$/i', $compact, $m)) {
            $candidates[] = $m[1] . '-' . $m[2];
        }
        $candidates = array_values(array_unique($candidates));

        $row = $this->db->table('guest_passes g')
            ->select('g.*', 'r.full_name AS resident_name', 'r.apartment')
            ->leftJoin('residents r', 'r.id', '=', 'g.resident_id')
            ->where(static function ($q) use ($candidates, $compact): void {
                $q->whereIn('g.code', $candidates)->orWhere('g.pin', '=', $compact);
            })
            ->first();

        return $row === null ? null : GuestPass::fromRow($row);
    }

    public function revoke(int $id, string $reason = '', ?User $actor = null, ?Request $request = null): bool
    {
        $pass = $this->find($id);
        if ($pass === null || $pass->isRevoked()) {
            return false;
        }
        $this->db->table('guest_passes')->where('id', '=', $id)->update([
            'status' => GuestPass::STATUS_REVOKED,
            'revoked_at' => $this->clock->format(),
            'revoked_by' => $actor?->id,
            'revoke_reason' => mb_substr($reason, 0, 190) ?: null,
            'updated_at' => $this->clock->format(),
        ]);

        $resident = $pass->residentId !== null ? $this->residents->findById($pass->residentId) : null;
        $this->audit->record('guest.revoke', $actor, [
            'subject_type' => 'guest_pass', 'subject_id' => (string) $id, 'subject_label' => $pass->code,
            'meta' => ['reason' => $reason, 'resident_id' => $pass->residentId],
            'is_security' => true,
        ], $request);
        $this->writeGuestLog($pass, $resident, 'revoked', $request, $actor, $reason);

        return true;
    }

    public function extend(int $id, int $hours, ?User $actor = null, ?Request $request = null): bool
    {
        $pass = $this->find($id);
        if ($pass === null || $pass->isRevoked()) {
            return false;
        }
        $hours = max(1, min($this->config->int('guest.max_ttl_hours', 72), $hours));
        $base = $pass->validUntil !== null && strtotime($pass->validUntil) > $this->clock->now()
            ? strtotime($pass->validUntil)
            : $this->clock->now();

        $this->db->table('guest_passes')->where('id', '=', $id)->update([
            'valid_until' => $this->clock->format('Y-m-d H:i:s', $base + $hours * 3600),
            'status' => GuestPass::STATUS_ACTIVE,
            'updated_at' => $this->clock->format(),
        ]);
        $this->audit->info('guest.extend', $actor, 'guest_pass', (string) $id, $pass->code, $request, ['hours' => $hours]);

        return true;
    }

    /** Mark expired passes (cron). */
    public function expireStale(): int
    {
        $now = $this->clock->format();
        $count = $this->db->table('guest_passes')
            ->where('status', '=', GuestPass::STATUS_ACTIVE)
            ->where('valid_until', '<', $now)
            ->update(['status' => GuestPass::STATUS_EXPIRED, 'updated_at' => $now]);

        // Passes that exhausted their use count.
        $this->db->table('guest_passes')
            ->where('status', '=', GuestPass::STATUS_ACTIVE)
            ->where('max_uses', '>', 0)
            ->whereRaw('used_count >= max_uses')
            ->update(['status' => GuestPass::STATUS_USED, 'updated_at' => $now]);

        return $count;
    }

    public function purgeOld(int $keepDays = 90): int
    {
        $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - max(1, $keepDays) * 86400);

        return $this->db->table('guest_passes')
            ->whereIn('status', [GuestPass::STATUS_EXPIRED, GuestPass::STATUS_REVOKED, GuestPass::STATUS_USED])
            ->where('valid_until', '<', $cutoff)
            ->delete();
    }

    /* ─────────────────────────── queries ─────────────────────────── */

    public function countActiveFor(int $residentId): int
    {
        return $this->db->table('guest_passes')
            ->where('resident_id', '=', $residentId)
            ->where('status', '=', GuestPass::STATUS_ACTIVE)
            ->where('valid_until', '>', $this->clock->format())
            ->count();
    }

    public function countCreatedToday(int $residentId): int
    {
        return $this->db->table('guest_passes')
            ->where('resident_id', '=', $residentId)
            ->where('created_at', '>=', $this->clock->format('Y-m-d 00:00:00'))
            ->count();
    }

    /** @return array<int, GuestPass> */
    public function activeForResident(int $residentId): array
    {
        return array_map(
            static fn (array $r): GuestPass => GuestPass::fromRow($r),
            $this->db->table('guest_passes')
                ->where('resident_id', '=', $residentId)
                ->where('status', '=', GuestPass::STATUS_ACTIVE)
                ->where('valid_until', '>', $this->clock->format())
                ->orderBy('valid_until')
                ->get()
        );
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('guest_passes g')
            ->select('g.*', 'r.full_name AS resident_name', 'r.apartment', 'r.is_active AS resident_active')
            ->leftJoin('residents r', 'r.id', '=', 'g.resident_id');

        $status = (string) ($filters['status'] ?? 'active');
        switch ($status) {
            case 'active':
                $q = $q->where('g.status', '=', GuestPass::STATUS_ACTIVE)->where('g.valid_until', '>', $this->clock->format());
                break;
            case 'expired':
                $q = $q->where(static function ($qq): void {
                    $qq->where('g.status', '=', GuestPass::STATUS_EXPIRED)
                       ->orWhere('g.valid_until', '<=', date('Y-m-d H:i:s'));
                });
                break;
            case 'revoked':
                $q = $q->where('g.status', '=', GuestPass::STATUS_REVOKED);
                break;
            case 'all':
                break;
            default:
                $q = $q->where('g.status', '=', $status);
        }

        if (!empty($filters['resident_id'])) {
            $q = $q->where('g.resident_id', '=', (int) $filters['resident_id']);
        }
        if (!empty($filters['only_active_residents'])) {
            $q = $q->where('r.is_active', '=', 1);
        }
        if (!empty($filters['from'])) {
            $q = $q->where('g.created_at', '>=', date('Y-m-d 00:00:00', (int) strtotime((string) $filters['from'])));
        }
        if (!empty($filters['to'])) {
            $q = $q->where('g.created_at', '<=', date('Y-m-d 23:59:59', (int) strtotime((string) $filters['to'])));
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['g.code', 'g.guest_name', 'g.guest_phone', 'g.car_plate', 'g.purpose', 'r.full_name', 'r.apartment'], (string) $filters['q']);
        }

        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = match ((string) ($filters['sort'] ?? 'created')) {
            'until'   => 'g.valid_until',
            'uses'    => 'g.used_count',
            'name'    => 'r.full_name',
            default   => 'g.created_at',
        };

        $paginator = $q->orderBy($sort, $dir)->orderBy('g.id', 'desc')->paginate($perPage, $page);

        return new Paginator(
            array_map(static fn (array $r): GuestPass => GuestPass::fromRow($r), $paginator->items()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->pageName()
        );
    }

    /** @return array<string, mixed> */
    public function stats(int $days = 30): array
    {
        $from = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $days * 86400);

        return [
            'active'   => $this->db->table('guest_passes')->where('status', '=', 'active')->where('valid_until', '>', $this->clock->format())->count(),
            'today'    => $this->db->table('guest_passes')->where('created_at', '>=', $this->clock->format('Y-m-d 00:00:00'))->count(),
            'period'   => $this->db->table('guest_passes')->where('created_at', '>=', $from)->count(),
            'used'     => (int) $this->db->table('guest_passes')->where('created_at', '>=', $from)->sum('used_count'),
            'revoked'  => $this->db->table('guest_passes')->where('status', '=', 'revoked')->where('created_at', '>=', $from)->count(),
            'expired'  => $this->db->table('guest_passes')->where('status', '=', 'expired')->where('created_at', '>=', $from)->count(),
        ];
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    /** Public URL of the one-time guest link. */
    public function linkFor(?string $token, mixed $baseOverride = null): string
    {
        $base = (string) ($baseOverride ?? $this->settings->string('guest.link_base', '') ?: $this->config->string('app.url', ''));
        $base = rtrim($base, '/');
        $path = '/guest/' . ($token ?? '');

        if ($base !== '') {
            return $base . $path;
        }

        return $path;      // relative — the caller prefixes the current host
    }

    /** Absolute link using the current request when the app URL is unknown. */
    public function absoluteLink(string $token, ?Request $request = null): string
    {
        $configured = $this->settings->string('guest.link_base', '') ?: $this->config->string('app.url', '');
        if ($configured !== '') {
            return rtrim($configured, '/') . '/guest/' . $token;
        }
        if ($request !== null) {
            return $request->scheme . '://' . ($request->header('host') ?? 'localhost') . $request->url('/guest/' . $token);
        }

        return '/guest/' . $token;
    }

    private function uniqueCode(int $attempts = 20): string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $code = Str::friendlyCode(2, 4, (string) $this->config->string('guest.code_alphabet', '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'));
            if ($this->db->table('guest_passes')->where('code', '=', $code)->count() === 0) {
                return $code;
            }
        }

        return 'G-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function uniquePin(int $attempts = 30): ?string
    {
        $length = max(4, (int) $this->config->int('guest.pin_length', 6));
        for ($i = 0; $i < $attempts; $i++) {
            $pin = (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);
            $taken = $this->db->table('guest_passes')
                ->where('pin', '=', $pin)
                ->where('status', '=', GuestPass::STATUS_ACTIVE)
                ->where('valid_until', '>', $this->clock->format())
                ->count();
            if ($taken === 0) {
                return $pin;
            }
        }

        return null;
    }

    private function clean(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = Str::squash((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function parseTime(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }

    /** Write a guest-pass lifecycle row into the gate log. */
    private function writeGuestLog(?GuestPass $pass, ?Resident $resident, string $action, ?Request $request, ?User $actor, string $reason = ''): void
    {
        if ($pass === null) {
            return;
        }
        try {
            $this->db->table('gate_events')->insert([
                'uuid'          => Crypto::uuidv4(),
                'occurred_at'   => $this->clock->format(),
                'actor_type'    => 'guest',
                'resident_id'   => $pass->residentId,
                'user_id'       => $actor?->id,
                'guest_pass_id' => $pass->id,
                'actor_name'    => mb_substr($resident?->displayName() ?? 'Житель', 0, 190),
                'apartment'     => $resident?->apartment,
                'access_type'   => GateEvent::ACCESS_GUEST,
                'action'        => $action,
                'source'        => $request !== null ? ($actor !== null && $actor->isStaff() ? 'admin' : 'cabinet') : 'cli',
                'result'        => GateEvent::RESULT_SUCCESS,
                'reason'        => $reason !== '' ? mb_substr($reason, 0, 190) : ('Гостевой доступ ' . $action . ': ' . $pass->code . ' до ' . $pass->validUntil),
                'plate'         => $pass->carPlateNormalized,
                'ip'            => $request?->ip($this->config),
                'user_agent'    => $request?->userAgent(),
                'request_id'    => $request?->requestId(),
                'meta'          => (string) json_encode([
                    'code' => $pass->code, 'ttl_hours' => $pass->ttlHours,
                    'valid_until' => $pass->validUntil, 'guest_name' => $pass->guestName,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            // never break pass issuance because of a log row
        }
    }

    private function notifyResident(Resident $resident, GuestPass $pass, string $link): void
    {
        $context = [
            'name'       => $resident->shortName(),
            'code'       => $pass->code,
            'pin'        => (string) ($pass->pin ?? ''),
            'link'       => $link,
            'expires_at' => $pass->validUntil !== null ? date('d.m.Y H:i', (int) strtotime($pass->validUntil)) : '',
            'ttl_hours'  => $pass->ttlHours,
            'guest_name' => (string) ($pass->guestName ?? ''),
            'car_plate'  => $pass->displayPlate(),
            'apartment'  => $resident->apartment,
        ];
        $rendered = $this->notify->renderTemplate('guest_created', $context);

        $this->notify->queue(
            Message::forResident($resident)
                ->template('guest_created')
                ->subject($rendered['subject'])
                ->body($rendered['body'])
                ->context($context),
            $resident
        );
    }

    private function notifyGuest(GuestPass $pass, string $link): void
    {
        $this->notify->queue(
            Message::make()
                ->channel('sms')
                ->to((string) $pass->guestPhone)
                ->template('guest_invite')
                ->subject('Гостевой доступ')
                ->body(sprintf(
                    "Гостевой доступ к шлагбауму до %s.\nСсылка: %s\nКод: %s%s",
                    $pass->validUntil !== null ? date('d.m.Y H:i', (int) strtotime($pass->validUntil)) : '—',
                    $link,
                    $pass->code,
                    $pass->pin !== null ? "\nPIN: " . $pass->pin : ''
                ))
        );
    }
}
