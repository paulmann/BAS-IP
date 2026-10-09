<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\ApiPrincipal;
use BasIp\Auth\User;
use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Events;
use BasIp\Core\Logger;
use BasIp\Core\RateLimiter;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Device\DeviceManager;
use BasIp\Device\GateResult;
use BasIp\Domain\Model\Device;
use BasIp\Domain\Model\GateEvent;
use BasIp\Domain\Model\GuestPass;
use BasIp\Domain\Model\Resident;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Support\Paginator;
use BasIp\Support\Plate;
use BasIp\Support\Str;
use Throwable;

/**
 * Gate orchestration.
 *
 * Every barrier movement goes through {@see open()} — one funnel that
 *   1. authorises the actor (resident flag, guest pass validity, staff role),
 *   2. applies policies (cooldown, rate limit, quiet hours, debtor block),
 *   3. talks to the device driver,
 *   4. writes an immutable `gate_events` row **regardless of the outcome**,
 *   5. fires the `gate.opened` / `gate.denied` events for side-effects
 *      (notifications, statistics, cache invalidation).
 *
 * Failures are logged too: "denied" is exactly as important as "success" when
 * somebody asks why the barrier did not open at 2 a.m.
 */
final class GateService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly DeviceManager $devices,
        private readonly ResidentRepository $residents,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly RateLimiter $limiter,
        private readonly Events $events,
        private readonly Cache $cache,
    ) {
    }

    /* ═══════════════════════════ open / close ═══════════════════════════ */

    /**
     * Open the barrier.
     *
     * @param array<string, mixed> $context
     *   resident?: Resident, guest_pass?: GuestPass, access_type, source,
     *   device?: string (slug), channel?: int, plate?: string, note?: string,
     *   user?: User, api?: ApiPrincipal, force?: bool (staff override)
     */
    public function open(array $context = [], ?Request $request = null): GateResult
    {
        $startedAt = microtime(true);
        $accessType = (string) ($context['access_type'] ?? $this->inferAccessType($context));
        $source     = (string) ($context['source'] ?? ($request !== null ? 'web' : 'cli'));
        $device     = $this->devices->resolve(isset($context['device']) ? (string) $context['device'] : null);
        $channel    = (int) ($context['channel'] ?? $device?->channel ?? $this->config->int('device.lock.channel', 1));
        $force      = (bool) ($context['force'] ?? false);

        /** @var Resident|null $resident */
        $resident = $context['resident'] ?? null;
        /** @var GuestPass|null $guestPass */
        $guestPass = $context['guest_pass'] ?? null;
        /** @var User|null $user */
        $user = $context['user'] ?? null;
        /** @var ApiPrincipal|null $api */
        $api = $context['api'] ?? null;

        // ── 1. Authorisation ────────────────────────────────────────────
        $denied = $force ? null : $this->authorize($resident, $guestPass, $user, $api, $accessType);
        if ($denied !== null) {
            return $this->logAndFail($denied['reason'], $denied['code'], $resident, $guestPass, $user, $api, $device, $channel, $accessType, $source, $request, $startedAt);
        }

        // ── 2. Policies (cooldown / rate limit / quiet hours / debts) ───
        $policy = $this->checkPolicies($resident, $user, $accessType, $request, $force);
        if ($policy !== null) {
            return $this->logAndFail($policy['reason'], $policy['code'], $resident, $guestPass, $user, $api, $device, $channel, $accessType, $source, $request, $startedAt);
        }

        if ($device === null) {
            return $this->logAndFail('Устройство не настроено.', 'no_device', $resident, $guestPass, $user, $api, null, $channel, $accessType, $source, $request, $startedAt);
        }

        // ── 3. Talk to the hardware ─────────────────────────────────────
        $driver = $this->devices->driver($device);
        try {
            $result = $driver->open($channel);
        } catch (Throwable $e) {
            $this->logger->channel('device')->error('Gate open exception', ['error' => $e->getMessage(), 'device' => $device->slug]);
            $result = GateResult::failure('Ошибка устройства: ' . $e->getMessage(), 'exception');
        }

        $this->devices->recordStatus($device, $result);
        $this->cache->forget('gate.status');

        // ── 4. Log ──────────────────────────────────────────────────────
        $eventId = $this->writeEvent(
            result: $result->ok ? GateEvent::RESULT_SUCCESS : GateEvent::RESULT_ERROR,
            accessType: $accessType,
            action: 'open',
            source: $source,
            resident: $resident,
            guestPass: $guestPass,
            user: $user,
            api: $api,
            device: $device,
            channel: $channel,
            request: $request,
            reason: $result->ok ? (string) ($context['note'] ?? '') : $result->message,
            plate: isset($context['plate']) ? (string) $context['plate'] : ($guestPass?->carPlate ?? null),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            deviceResponse: $result->ok ? null : ['status' => $result->status, 'error' => $result->message, 'code' => $result->errorCode],
            meta: ['ok' => $result->ok, 'message' => $result->message]
        );

        // ── 5. Side effects ─────────────────────────────────────────────
        if ($result->ok) {
            if ($guestPass !== null) {
                $this->registerGuestUse($guestPass, $request);
            }
            if ($resident !== null) {
                $this->db->table('residents')->where('id', '=', $resident->id)->update(['last_gate_event_at' => $this->clock->format()]);
            }
            $this->audit->record('gate.open', $user, [
                'subject_type' => 'resident',
                'subject_id'   => $resident !== null ? (string) $resident->id : null,
                'subject_label' => $this->actorLabel($resident, $guestPass, $user, $api),
                'meta' => ['access_type' => $accessType, 'source' => $source, 'device' => $device->slug, 'event_id' => $eventId],
            ], $request);

            $this->events->dispatch('gate.opened', [
                'event_id' => $eventId, 'resident' => $resident, 'guest_pass' => $guestPass,
                'access_type' => $accessType, 'source' => $source, 'device' => $device->slug,
            ]);
        } else {
            $this->events->dispatch('gate.failed', [
                'event_id' => $eventId, 'resident' => $resident, 'error' => $result->message, 'device' => $device->slug,
            ]);
        }

        return $result;
    }

    /** @param array<string, mixed> $context */
    public function close(array $context = [], ?Request $request = null): GateResult
    {
        $device = $this->devices->resolve(isset($context['device']) ? (string) $context['device'] : null);
        $channel = (int) ($context['channel'] ?? $device?->channel ?? 1);
        if ($device === null) {
            return GateResult::failure('Устройство не настроено.', 'no_device');
        }
        $driver = $this->devices->driver($device);
        if (!$driver->supports('close')) {
            return GateResult::failure('Драйвер не поддерживает закрытие.', 'unsupported');
        }

        $result = $driver->close($channel);
        $this->writeEvent(
            result: $result->ok ? GateEvent::RESULT_SUCCESS : GateEvent::RESULT_ERROR,
            accessType: GateEvent::ACCESS_MANUAL,
            action: 'close',
            source: (string) ($context['source'] ?? 'admin'),
            resident: $context['resident'] ?? null,
            user: $context['user'] ?? null,
            device: $device,
            channel: $channel,
            request: $request,
            reason: $result->message
        );
        $this->audit->record('gate.close', $context['user'] ?? null, [
            'subject_type' => 'device', 'subject_id' => $device->slug, 'meta' => ['ok' => $result->ok],
        ], $request);

        return $result;
    }

    public function status(?string $deviceSlug = null, bool $useCache = true): GateResult
    {
        $cacheKey = 'gate.status.' . ($deviceSlug ?? 'default');
        if ($useCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && ($cached['at'] ?? 0) > $this->clock->now() - 15) {
                return new GateResult((bool) $cached['ok'], (string) $cached['message'], (array) $cached['data'], $cached['status'] ?? null, [], (float) ($cached['ms'] ?? 0));
            }
        }

        $device = $this->devices->resolve($deviceSlug);
        if ($device === null) {
            return GateResult::failure('Устройство не настроено. Проверьте config/device или раздел «Устройства».', 'no_device');
        }

        try {
            $result = $this->devices->driver($device)->status();
        } catch (Throwable $e) {
            $result = GateResult::failure('Ошибка запроса статуса: ' . $e->getMessage(), 'exception');
        }
        $this->devices->recordStatus($device, $result);
        $this->cache->put($cacheKey, [
            'ok' => $result->ok, 'message' => $result->message, 'data' => $result->data,
            'status' => $result->status, 'ms' => $result->durationMs, 'at' => $this->clock->now(),
        ], 15);

        return $result;
    }

    public function time(?string $deviceSlug = null): GateResult
    {
        $device = $this->devices->resolve($deviceSlug);

        return $device === null
            ? GateResult::failure('Устройство не настроено.', 'no_device')
            : $this->devices->driver($device)->time();
    }

    public function reboot(?string $deviceSlug = null, ?User $actor = null, ?Request $request = null): GateResult
    {
        $device = $this->devices->resolve($deviceSlug);
        if ($device === null) {
            return GateResult::failure('Устройство не настроено.', 'no_device');
        }
        $result = $this->devices->driver($device)->reboot();
        $this->cache->forget('gate.status.' . ($deviceSlug ?? 'default'));
        $this->audit->info('gate.reboot', $actor, 'device', $device->slug, $device->label(), $request, ['ok' => $result->ok]);

        return $result;
    }

    public function sip(bool $enable, ?string $deviceSlug = null, ?User $actor = null, ?Request $request = null): GateResult
    {
        $device = $this->devices->resolve($deviceSlug);
        if ($device === null) {
            return GateResult::failure('Устройство не настроено.', 'no_device');
        }
        $result = $this->devices->driver($device)->sipEnable($enable);
        $this->audit->info($enable ? 'gate.sip_enable' : 'gate.sip_disable', $actor, 'device', $device->slug, $device->label(), $request, ['ok' => $result->ok]);

        return $result;
    }

    /** JPEG frame (or null). */
    public function snapshot(?string $deviceSlug = null): ?string
    {
        $device = $this->devices->resolve($deviceSlug);
        if ($device === null || !$this->config->bool('device.camera.enabled', true)) {
            return null;
        }

        return $this->devices->driver($device)->snapshot();
    }

    /** @return array<int, array<string, mixed>> */
    public function cameras(?string $deviceSlug = null): array
    {
        $device = $this->devices->resolve($deviceSlug);
        if ($device === null) {
            return [];
        }
        $result = $this->devices->driver($device)->cameras();

        return (array) $result->get('cameras', []);
    }

    /* ═══════════════════════════ authorisation & policies ═══════════════════════════ */

    /**
     * @return array{reason: string, code: string}|null  null = allowed
     */
    public function authorize(?Resident $resident, ?GuestPass $guestPass, ?User $user, ?ApiPrincipal $api, string $accessType): ?array
    {
        // Staff and API tokens are role-checked upstream, so they may open the
        // barrier. But when they open *on behalf of a specific resident*, that
        // resident's own flags still apply — otherwise suspending a resident
        // (e.g. for non-payment) would be meaningless, since any dispatcher
        // could open for them anyway. Emergencies use `force`, which skips this
        // whole method.
        $isStaff = ($user !== null && $user->isStaff() && $user->can('gate.open'))
            || ($api !== null && $api->can('gate.open'));
        if ($isStaff && $resident === null && $guestPass === null) {
            return null;
        }

        if ($guestPass !== null) {
            if (!$guestPass->isActive($this->clock->now())) {
                return ['reason' => $this->guestDenyReason($guestPass), 'code' => 'guest_invalid'];
            }
            if ($guestPass->residentId !== null) {
                $owner = $resident ?? $this->residents->findById($guestPass->residentId);
                if ($owner !== null && !$owner->isActive) {
                    return ['reason' => 'Владелец гостевого доступа отключён.', 'code' => 'owner_inactive'];
                }
                if ($owner !== null && !$owner->accessGranted && $this->settings->bool('gate.require_access_flag', true)) {
                    return ['reason' => 'Доступ владельца приостановлен.', 'code' => 'owner_no_access'];
                }
            }

            return null;
        }

        if ($resident === null) {
            return ['reason' => 'Не указан житель.', 'code' => 'no_actor'];
        }
        if ($resident->deletedAt !== null) {
            return ['reason' => 'Запись жителя удалена.', 'code' => 'deleted'];
        }
        if (!$resident->isActive) {
            return ['reason' => 'Доступ отключён администратором (житель не активен).', 'code' => 'inactive'];
        }
        if (!$resident->accessGranted && $this->settings->bool('gate.require_access_flag', true)) {
            return ['reason' => 'Доступ приостановлен (снята галочка «доступ»).', 'code' => 'no_access'];
        }

        return null;
    }

    private function guestDenyReason(GuestPass $pass): string
    {
        return match (true) {
            $pass->isRevoked()               => 'Гостевой доступ отозван.',
            $pass->isExpired($this->clock->now()) => 'Срок гостевого доступа истёк.',
            $pass->maxUses > 0 && $pass->usedCount >= $pass->maxUses => 'Исчерпан лимит использований гостевого доступа.',
            $pass->validFrom !== null && strtotime($pass->validFrom) > $this->clock->now() => 'Гостевой доступ ещё не начал действовать.',
            default                          => 'Гостевой доступ недействителен.',
        };
    }

    /** @return array{reason: string, code: string}|null */
    private function checkPolicies(?Resident $resident, ?User $user, string $accessType, ?Request $request, bool $force): ?array
    {
        $now = $this->clock->now();

        // Cooldown — protects against double taps and relay chatter.
        $cooldown = max(0, $this->settings->int('gate.open_cooldown', $this->config->int('device.lock.cooldown_seconds', 3)));
        if ($cooldown > 0 && !$force) {
            $key = 'gate.cooldown.' . ($resident?->id ?? 0) . '.' . ($user?->id ?? 0);
            $last = (int) $this->cache->get($key, 0);
            if ($last > 0 && $now - $last < $cooldown) {
                return [
                    'reason' => sprintf('Слишком часто. Повторите через %d с.', $cooldown - ($now - $last)),
                    'code'   => 'cooldown',
                ];
            }
            $this->cache->put($key, $now, $cooldown + 5);
        }

        // Rate limit per actor.
        $rule = $this->config->section('security.rate_limits.gate_open');
        if (!$force && isset($rule['max'])) {
            $identity = $resident !== null ? 'r' . $resident->id : ('u' . ($user?->id ?? $request?->ip($this->config) ?? 'anon'));
            $check = $this->limiter->attempt('gate_open:' . $identity, (int) $rule['max'], (int) ($rule['window'] ?? 60));
            if (!$check['allowed']) {
                return [
                    'reason' => 'Превышен лимит открытий (' . $check['limit'] . ' в минуту). Повторите через ' . $check['retryAfter'] . ' с.',
                    'code'   => 'rate_limited',
                ];
            }
        }

        // Quiet hours.
        if ($this->settings->bool('gate.quiet_hours_enabled', false) && !$force && $accessType !== GateEvent::ACCESS_EMERGENCY) {
            $inQuiet = $this->isQuietHours();
            $guestAllowed = $this->settings->bool('gate.allow_guest_at_night', true);
            if ($inQuiet && ($accessType !== GateEvent::ACCESS_GUEST || !$guestAllowed)) {
                return [
                    'reason' => sprintf(
                        'Тихие часы: с %s до %s открытие доступно только диспетчеру.',
                        $this->settings->string('gate.quiet_hours_start', '23:00'),
                        $this->settings->string('gate.quiet_hours_end', '07:00')
                    ),
                    'code' => 'quiet_hours',
                ];
            }
        }

        // Time-window schedules.
        if (!$force && ($denied = $this->checkSchedule($resident)) !== null) {
            return $denied;
        }

        // Optional debtor block.
        if ($this->settings->bool('gate.block_debtors', false) && !$force && $resident !== null && $accessType === GateEvent::ACCESS_OWN) {
            $threshold = $this->settings->float('gate.debt_threshold', 0.0);
            $statement = $this->db->table('invoices')
                ->where('resident_id', '=', $resident->id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->sum('balance');
            if ((float) $statement > max(0.0, $threshold)) {
                return [
                    'reason' => 'Доступ ограничен из-за задолженности (' . \BasIp\Support\Money::format((float) $statement) . ').',
                    'code'   => 'debt',
                ];
            }
        }

        return null;
    }

    public function isQuietHours(?int $timestamp = null): bool
    {
        $timestamp ??= $this->clock->now();
        $start = $this->settings->string('gate.quiet_hours_start', '23:00');
        $end   = $this->settings->string('gate.quiet_hours_end', '07:00');
        $current = date('H:i', $timestamp);

        if ($start === $end) {
            return false;
        }

        return $start <= $end
            ? ($current >= $start && $current < $end)          // same-day window
            : ($current >= $start || $current < $end);          // overnight window
    }

    /** @return array{reason: string, code: string}|null */
    private function checkSchedule(?Resident $resident): ?array
    {
        try {
            $rows = $this->db->table('access_schedules')->where('is_active', '=', 1)->orderBy('priority', 'desc')->get();
        } catch (Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }

        $now = $this->clock->now();
        $weekday = (int) date('N', $now);          // 1 = Monday
        $time = date('H:i', $now);

        foreach ($rows as $row) {
            $appliesTo = $row['resident_id'] ?? null;
            if ($appliesTo !== null && (int) $appliesTo !== ($resident?->id ?? -1)) {
                continue;
            }
            $days = array_map('intval', array_filter(explode(',', (string) ($row['days'] ?? ''))));
            if ($days !== [] && !in_array($weekday, $days, true)) {
                continue;
            }
            $start = (string) ($row['start_time'] ?? '00:00');
            $end   = (string) ($row['end_time'] ?? '23:59');
            $inside = $start <= $end ? ($time >= $start && $time <= $end) : ($time >= $start || $time <= $end);
            if (!$inside) {
                continue;
            }
            if (($row['effect'] ?? 'allow') === 'deny') {
                return [
                    'reason' => 'Открытие запрещено расписанием «' . ($row['name'] ?? 'без названия') . '» (' . $start . '–' . $end . ').',
                    'code'   => 'schedule',
                ];
            }
        }

        return null;
    }

    /* ═══════════════════════════ logging ═══════════════════════════ */

    private function writeEvent(
        string $result,
        string $accessType,
        string $action,
        string $source,
        ?Resident $resident,
        ?GuestPass $guestPass,
        ?User $user,
        ?ApiPrincipal $api,
        ?Device $device,
        int $channel,
        ?Request $request,
        string $reason = '',
        ?string $plate = null,
        int $durationMs = 0,
        ?array $deviceResponse = null,
        array $meta = [],
    ): int {
        $actorType = match (true) {
            $guestPass !== null        => 'guest',
            $user !== null && $user->isAdmin()    => 'admin',
            $user !== null && $user->isOperator() => 'operator',
            $user !== null && $user->isResident() => 'resident',
            $api !== null              => 'api',
            $resident !== null         => 'resident',
            default                    => 'system',
        };

        $row = [
            'uuid'         => Crypto::uuidv4(),
            'occurred_at'  => $this->clock->format(),
            'device_id'    => $device !== null && $device->id > 0 ? $device->id : null,
            'channel'      => $channel,
            'actor_type'   => $actorType,
            'resident_id'  => $resident?->id ?? $guestPass?->residentId,
            'user_id'      => $user?->id,
            'guest_pass_id' => $guestPass?->id,
            'actor_name'   => mb_substr($this->actorLabel($resident, $guestPass, $user, $api), 0, 190),
            'apartment'    => $resident?->apartment,
            'access_type'  => $accessType,
            'action'       => $action,
            'source'       => $source,
            'result'       => $result,
            'reason'       => $reason !== '' ? mb_substr($reason, 0, 190) : null,
            'plate'        => $plate !== null && $plate !== '' ? mb_substr(Plate::normalize($plate), 0, 32) : null,
            'ip'           => $request?->ip($this->config),
            'user_agent'   => $request !== null ? $request->userAgent() : 'cli',
            'request_id'   => $request?->requestId(),
            'duration_ms'  => $durationMs > 0 ? $durationMs : null,
            'device_response' => $deviceResponse === null ? null : (string) json_encode($deviceResponse, JSON_UNESCAPED_UNICODE),
            'meta'         => $meta === [] ? null : (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
        ];

        try {
            $id = $this->db->table('gate_events')->insertGetId($row);
        } catch (Throwable $e) {
            $this->logger->channel('gate')->error('Не удалось записать событие шлагбаума', ['error' => $e->getMessage()]);
            $id = 0;
        }

        // Separate human-readable log line — survives even a broken DB.
        $this->logger->channel('gate')->log($result === GateEvent::RESULT_SUCCESS ? 'info' : 'warning', 'gate.' . $action, [
            'result'      => $result,
            'access_type' => $accessType,
            'actor'       => $row['actor_name'],
            'apartment'   => $row['apartment'],
            'device'      => $device?->slug,
            'channel'     => $channel,
            'source'      => $source,
            'duration_ms' => $durationMs,
            'reason'      => $reason !== '' ? $reason : null,
            'event_id'    => $id,
        ]);

        return $id;
    }

    private function logAndFail(string $reason, string $code, ?Resident $resident, ?GuestPass $guestPass, ?User $user, ?ApiPrincipal $api, ?Device $device, int $channel, string $accessType, string $source, ?Request $request, float $startedAt): GateResult
    {
        $this->writeEvent(
            result: GateEvent::RESULT_DENIED,
            accessType: $accessType,
            action: 'denied',
            source: $source,
            resident: $resident,
            guestPass: $guestPass,
            user: $user,
            api: $api,
            device: $device,
            channel: $channel,
            request: $request,
            reason: $reason,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            meta: ['code' => $code]
        );

        $this->events->dispatch('gate.denied', ['reason' => $reason, 'code' => $code, 'resident' => $resident, 'guest_pass' => $guestPass]);

        return GateResult::failure($reason, $code, ['code' => $code]);
    }

    private function registerGuestUse(GuestPass $pass, ?Request $request): void
    {
        $update = [
            'used_count'   => $pass->usedCount + 1,
            'last_used_at' => $this->clock->format(),
            'ip_last_used' => $request?->ip($this->config),
            'updated_at'   => $this->clock->format(),
        ];
        if ($pass->maxUses > 0 && $pass->usedCount + 1 >= $pass->maxUses) {
            $update['status'] = GuestPass::STATUS_USED;
        }
        $this->db->table('guest_passes')->where('id', '=', $pass->id)->update($update);
    }

    private function actorLabel(?Resident $resident, ?GuestPass $guestPass, ?User $user, ?ApiPrincipal $api): string
    {
        if ($guestPass !== null) {
            $who = $guestPass->guestName ?? 'Гость';
            $owner = $resident?->displayName() ?? ($guestPass->residentName ?? '');

            return $owner !== '' ? sprintf('%s (гость %s)', $owner, $who) : $who;
        }
        if ($user !== null && $user->isStaff()) {
            return $user->displayName() . ' (' . $user->roleLabel() . ')';
        }
        if ($api !== null) {
            return 'API: ' . $api->name;
        }
        if ($resident !== null) {
            return $resident->displayName();
        }
        if ($user !== null) {
            return $user->displayName();
        }

        return 'Система';
    }

    private function inferAccessType(array $context): string
    {
        if (($context['guest_pass'] ?? null) !== null) {
            return GateEvent::ACCESS_GUEST;
        }
        if (($context['api'] ?? null) !== null) {
            return GateEvent::ACCESS_API;
        }
        $user = $context['user'] ?? null;
        if ($user instanceof User && $user->isStaff()) {
            return GateEvent::ACCESS_MANUAL;
        }
        if (($context['resident'] ?? null) !== null) {
            return GateEvent::ACCESS_OWN;
        }
        if (!empty($context['plate'])) {
            return GateEvent::ACCESS_PLATE;
        }

        return GateEvent::ACCESS_REMOTE;
    }

    /* ═══════════════════════════ queries & stats ═══════════════════════════ */

    /** @param array<string, mixed> $filters */
    public function events(array $filters = [], int $perPage = 50, int $page = 1): Paginator
    {
        $q = $this->db->table('gate_events e')
            ->select('e.*', 'r.full_name AS resident_name', 'r.apartment AS resident_apartment', 'd.name AS device_name', 'g.code AS guest_code')
            ->leftJoin('residents r', 'r.id', '=', 'e.resident_id')
            ->leftJoin('devices d', 'd.id', '=', 'e.device_id')
            ->leftJoin('guest_passes g', 'g.id', '=', 'e.guest_pass_id');

        if (!empty($filters['from'])) {
            $q = $q->where('e.occurred_at', '>=', date('Y-m-d 00:00:00', (int) strtotime((string) $filters['from'])));
        }
        if (!empty($filters['to'])) {
            $q = $q->where('e.occurred_at', '<=', date('Y-m-d 23:59:59', (int) strtotime((string) $filters['to'])));
        }
        if (!empty($filters['access_type'])) {
            $q = $q->where('e.access_type', '=', (string) $filters['access_type']);
        }
        if (!empty($filters['result'])) {
            $q = $q->where('e.result', '=', (string) $filters['result']);
        }
        if (!empty($filters['source'])) {
            $q = $q->where('e.source', '=', (string) $filters['source']);
        }
        if (!empty($filters['actor_type'])) {
            $q = $q->where('e.actor_type', '=', (string) $filters['actor_type']);
        }
        if (!empty($filters['resident_id'])) {
            $q = $q->where('e.resident_id', '=', (int) $filters['resident_id']);
        }
        if (!empty($filters['guest_pass_id'])) {
            $q = $q->where('e.guest_pass_id', '=', (int) $filters['guest_pass_id']);
        }
        if (!empty($filters['device_id'])) {
            $q = $q->where('e.device_id', '=', (int) $filters['device_id']);
        }
        if (!empty($filters['plate'])) {
            $q = $q->where('e.plate', '=', Plate::normalize((string) $filters['plate']));
        }
        if (!empty($filters['only_guest'])) {
            $q = $q->where('e.access_type', '=', GateEvent::ACCESS_GUEST);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['e.actor_name', 'e.apartment', 'r.full_name', 'e.plate', 'e.reason', 'g.code'], (string) $filters['q']);
        }

        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $paginator = $q->orderBy('e.occurred_at', $dir)->orderBy('e.id', $dir)->paginate($perPage, $page);

        return new Paginator(
            array_map(static fn (array $r): GateEvent => GateEvent::fromRow($r), $paginator->items()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->pageName()
        );
    }

    /** @return array<int, GateEvent> */
    public function recentEvents(int $limit = 20, bool $onlySuccess = false): array
    {
        $q = $this->db->table('gate_events e')
            ->select('e.*', 'r.full_name AS resident_name', 'g.code AS guest_code')
            ->leftJoin('residents r', 'r.id', '=', 'e.resident_id')
            ->leftJoin('guest_passes g', 'g.id', '=', 'e.guest_pass_id');
        if ($onlySuccess) {
            $q = $q->where('e.result', '=', GateEvent::RESULT_SUCCESS);
        }

        return array_map(
            static fn (array $r): GateEvent => GateEvent::fromRow($r),
            $q->orderBy('e.occurred_at', 'desc')->orderBy('e.id', 'desc')->limit($limit)->get()
        );
    }

    /** @return array<int, GateEvent> */
    public function eventsForResident(int $residentId, int $limit = 100): array
    {
        return array_map(
            static fn (array $r): GateEvent => GateEvent::fromRow($r),
            $this->db->table('gate_events')
                ->where('resident_id', '=', $residentId)
                ->orderBy('occurred_at', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Dashboard counters.
     *
     * @return array<string, mixed>
     */
    public function stats(int $days = 30): array
    {
        $from = $this->clock->format('Y-m-d 00:00:00', $this->clock->now() - $days * 86400);

        $totals = $this->db->table('gate_events')
            ->select(
                'COUNT(*) AS total',
                "COALESCE(SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END), 0) AS success",
                "COALESCE(SUM(CASE WHEN result = 'denied' THEN 1 ELSE 0 END), 0) AS denied",
                "COALESCE(SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END), 0) AS errors",
                "COALESCE(SUM(CASE WHEN access_type = 'guest' THEN 1 ELSE 0 END), 0) AS guest",
                "COALESCE(SUM(CASE WHEN access_type = 'own' THEN 1 ELSE 0 END), 0) AS own",
                "COALESCE(SUM(CASE WHEN access_type = 'manual' THEN 1 ELSE 0 END), 0) AS manual"
            )
            ->where('occurred_at', '>=', $from)
            ->first();

        $today = $this->clock->format('Y-m-d 00:00:00');
        $todayCount = (int) $this->db->table('gate_events')->where('occurred_at', '>=', $today)->count();

        return [
            'period_days' => $days,
            'total'    => (int) ($totals['total'] ?? 0),
            'success'  => (int) ($totals['success'] ?? 0),
            'denied'   => (int) ($totals['denied'] ?? 0),
            'errors'   => (int) ($totals['errors'] ?? 0),
            'guest'    => (int) ($totals['guest'] ?? 0),
            'own'      => (int) ($totals['own'] ?? 0),
            'manual'   => (int) ($totals['manual'] ?? 0),
            'today'    => $todayCount,
            'by_day'   => $this->opensByDay($days),
            'by_type'  => $this->opensByType($from),
            'top_residents' => $this->topResidents($from, 8),
        ];
    }

    /** @return array<int, array{date: string, label: string, count: int}> */
    public function opensByDay(int $days = 30): array
    {
        $out = [];
        $rows = $this->db->table('gate_events')
            ->select($this->db->isPgsql() ? "TO_CHAR(occurred_at, 'YYYY-MM-DD') AS day" : ($this->db->isSqlite() ? "DATE(occurred_at) AS day" : 'DATE(occurred_at) AS day'), 'COUNT(*) AS cnt')
            ->where('occurred_at', '>=', $this->clock->format('Y-m-d 00:00:00', $this->clock->now() - $days * 86400))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['cnt'];
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $this->clock->format('Y-m-d', $this->clock->now() - $i * 86400);
            $out[] = ['date' => $date, 'label' => date('d.m', (int) strtotime($date)), 'count' => $byDay[$date] ?? 0];
        }

        return $out;
    }

    /** @return array<int, array{type: string, label: string, count: int}> */
    public function opensByType(string $from): array
    {
        $rows = $this->db->table('gate_events')
            ->select('access_type', 'COUNT(*) AS cnt')
            ->where('occurred_at', '>=', $from)
            ->groupBy('access_type')
            ->orderBy('cnt', 'desc')
            ->get();

        return array_map(static function (array $row): array {
            $type = (string) $row['access_type'];

            return [
                'type'  => $type,
                'label' => GateEvent::fromRow(['access_type' => $type])->accessLabel(),
                'count' => (int) $row['cnt'],
            ];
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function topResidents(string $from, int $limit = 8): array
    {
        return $this->db->table('gate_events e')
            ->select('e.resident_id', 'r.full_name', 'r.apartment', 'COUNT(*) AS cnt')
            ->innerJoin('residents r', 'r.id', '=', 'e.resident_id')
            ->where('e.occurred_at', '>=', $from)
            ->where('e.result', '=', GateEvent::RESULT_SUCCESS)
            ->groupBy('e.resident_id', 'r.full_name', 'r.apartment')
            ->orderBy('cnt', 'desc')
            ->limit($limit)
            ->get();
    }

    /** Purge old events (cron / retention policy). */
    public function purgeEvents(int $keepDays): int
    {
        $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - max(1, $keepDays) * 86400);
        $count = $this->db->table('gate_events')->where('occurred_at', '<', $cutoff)->delete();
        if ($count > 0) {
            $this->audit->record('gate.events_purged', null, [
                'actor_name' => 'cron', 'subject_type' => 'gate_events',
                'meta' => ['deleted' => $count, 'older_than' => $cutoff],
            ]);
        }

        return $count;
    }

    /** Look a resident up by car plate (ANPR integrations). */
    public function findByPlate(string $plate): ?Resident
    {
        $normalized = Plate::normalize($plate);
        if ($normalized === '') {
            return null;
        }
        $car = $this->db->table('cars')->where('plate_normalized', '=', $normalized)->where('is_active', '=', 1)->first();
        if ($car === null) {
            return null;
        }

        return $this->residents->findById((int) $car['resident_id']);
    }
}
