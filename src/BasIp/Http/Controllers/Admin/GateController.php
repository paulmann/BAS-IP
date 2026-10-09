<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Device\DeviceManager;
use BasIp\Domain\Model\GateEvent;
use BasIp\Http\Controller;
use BasIp\Services\GateService;
use BasIp\Support\Str;

/**
 * Barrier operations for staff: the immutable open/deny log, statistics,
 * manual open/close/reboot, device management and access schedules.
 */
final class GateController extends Controller
{
    private function gate(): GateService
    {
        return $this->svc(GateService::class);
    }

    private function deviceManager(): DeviceManager
    {
        return $this->svc(DeviceManager::class);
    }

    /* ─────────────────────────── log ─────────────────────────── */

    public function events(Request $request): Response
    {
        $this->requirePermission('gate.log');
        $gate = $this->gate();

        $filters = $this->filters($request, [
            'q', 'from', 'to', 'access_type', 'result', 'source', 'actor_type',
            'resident_id', 'guest_pass_id', 'device_id', 'plate', 'only_guest',
        ]);
        // Sensible default window: today.
        if (!isset($filters['from']) && !isset($filters['to'])) {
            $filters['from'] = date('Y-m-d', strtotime('-7 days'));
        }

        $paginator = $gate->events($filters, $this->perPage($request, 50), $this->page($request));

        return $this->view('admin/gate/events', [
            'title'    => 'Журнал открываний',
            'events'   => $paginator,
            'filters'  => $filters,
            'query'    => $request->query,
            'stats'    => $gate->stats(30),
            'devices'  => array_values($this->deviceManager()->all(false)),
            'accessTypes' => $this->accessTypeList(),
            'results'  => ['success' => 'Успешно', 'denied' => 'Отказано', 'error' => 'Ошибка'],
            'sources'  => ['web' => 'Веб-панель', 'cabinet' => 'Личный кабинет', 'admin' => 'Админка', 'api' => 'API', 'cli' => 'CLI', 'telegram' => 'Telegram', 'panel' => 'Панель', 'cron' => 'Планировщик'],
            'actorTypes' => ['resident' => 'Житель', 'guest' => 'Гость', 'admin' => 'Администратор', 'operator' => 'Диспетчер', 'api' => 'API', 'system' => 'Система', 'panel' => 'Панель'],
            'canOpen'  => $this->requireUser()->can('gate.open'),
            'canExport' => $this->requireUser()->can('data.export'),
            'retention' => $this->settingsService()->get('gate.log_retention_days', 730),
        ]);
    }

    public function stats(Request $request): Response
    {
        $this->requirePermission('gate.log');
        $days = max(1, min(365, $request->int('days', 30)));
        $gate = $this->gate();

        return $this->view('admin/gate/stats', [
            'title'   => 'Статистика шлагбаума',
            'days'    => $days,
            'stats'   => $gate->stats($days),
            'byHour'  => $this->svc(\BasIp\Services\StatisticsService::class)->gateByHour(14),
            'top'     => $gate->topResidents($this->clockFormat('-' . $days . ' days'), 15),
            'denials' => $this->denialBreakdown($days),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function denialBreakdown(int $days): array
    {
        $from = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        return $this->db()->table('gate_events')
            ->select('reason', 'COUNT(*) AS cnt')
            ->whereIn('result', ['denied', 'error'])
            ->where('occurred_at', '>=', $from)
            ->whereNotNull('reason')
            ->groupBy('reason')
            ->orderBy('cnt', 'desc')
            ->limit(15)
            ->get();
    }

    private function clockFormat(string $relative): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($relative));
    }

    /** @return array<string, string> */
    private function accessTypeList(): array
    {
        return [
            GateEvent::ACCESS_OWN       => 'Свой доступ',
            GateEvent::ACCESS_GUEST     => 'Гостевой',
            GateEvent::ACCESS_MANUAL    => 'Вручную (диспетчер)',
            GateEvent::ACCESS_REMOTE    => 'Удалённо',
            GateEvent::ACCESS_PLATE     => 'По номеру авто',
            GateEvent::ACCESS_RFID      => 'По карте/метке',
            GateEvent::ACCESS_SCHEDULED => 'По расписанию',
            GateEvent::ACCESS_EMERGENCY => 'Аварийный',
            GateEvent::ACCESS_API       => 'Через API',
            GateEvent::ACCESS_TEST      => 'Тест',
        ];
    }

    public function export(Request $request): Response
    {
        $this->requirePermission('data.export');
        $gate = $this->gate();

        $filters = $this->filters($request, ['from', 'to', 'access_type', 'result', 'source', 'resident_id', 'device_id']);
        $delimiter = (string) $this->config()->string('data.csv_delimiter', ';');

        $rows = [['Дата и время', 'Кто', 'Квартира', 'Тип доступа', 'Действие', 'Источник', 'Результат', 'Причина', 'Номер авто', 'Устройство', 'IP', 'мс']];
        $page = 1;
        $count = 0;
        do {
            $paginator = $gate->events($filters, 500, $page);
            foreach ($paginator->items() as $event) {
                /** @var GateEvent $event */
                $rows[] = [
                    $event->when('Y-m-d H:i:s'), $event->actorLabel(), (string) ($event->apartment ?? ''),
                    $event->accessLabel(), $event->action, $event->sourceLabel(), $event->resultLabel(),
                    (string) ($event->reason ?? ''), (string) ($event->plate ?? ''),
                    (string) ($event->deviceName ?? ''), (string) ($event->ip ?? ''), (string) ($event->durationMs ?? ''),
                ];
                $count++;
            }
            $page++;
        } while ($paginator->hasMore() && $page <= 200);

        $csv = ($this->config()->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '');
        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(static function ($v) use ($delimiter): string {
                $v = (string) $v;

                return str_contains($v, $delimiter) || str_contains($v, '"') || str_contains($v, "\n")
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : $v;
            }, $row)) . "\r\n";
        }

        $this->auditService()->info('data.export.gate_events', $this->requireUser(), 'gate_events', null, $count . ' строк', $request, $filters);

        return Response::csv($csv, sprintf('gate-log-%s.csv', date('Ymd-His')));
    }

    /* ─────────────────────────── commands ─────────────────────────── */

    public function open(Request $request): Response
    {
        $user = $this->requirePermission('gate.open');
        $resident = $request->filled('resident_id') ? $this->residentRepo()->findById($request->int('resident_id')) : null;

        $result = $this->gate()->open([
            'resident'    => $resident,
            'user'        => $user,
            'access_type' => $resident !== null ? GateEvent::ACCESS_REMOTE : GateEvent::ACCESS_MANUAL,
            'source'      => 'admin',
            'device'      => $request->str('device') ?: null,
            'channel'     => $request->int('channel') ?: null,
            'note'        => $request->str('note') ?: 'Открыто из админки',
            'force'       => $request->bool('force'),
        ], $request);

        return $this->json([
            'ok' => $result->ok, 'message' => $result->message,
            'error_code' => $result->errorCode, 'duration' => round($result->durationMs),
        ], $result->ok ? 200 : 422);
    }

    public function close(Request $request): Response
    {
        $user = $this->requirePermission('gate.close');
        $result = $this->gate()->close(['user' => $user, 'source' => 'admin', 'device' => $request->str('device') ?: null], $request);

        return $this->json(['ok' => $result->ok, 'message' => $result->message], $result->ok ? 200 : 422);
    }

    public function reboot(Request $request): Response
    {
        $user = $this->requirePermission('gate.reboot');
        $result = $this->gate()->reboot($request->str('device') ?: null, $user, $request);

        return $this->respond($request, $result->ok, $result->ok ? 'Команда перезагрузки отправлена.' : $result->message, '/admin/gate/devices');
    }

    public function sip(Request $request): Response
    {
        $user = $this->requirePermission('gate.device.control');
        $result = $this->gate()->sip($request->bool('enable', true), $request->str('device') ?: null, $user, $request);

        return $this->respond($request, $result->ok, $result->ok ? ($request->bool('enable') ? 'SIP включён.' : 'SIP выключен.') : $result->message, '/admin/gate/devices');
    }

    /* ─────────────────────────── devices ─────────────────────────── */

    public function devices(Request $request): Response
    {
        $this->requirePermission('devices.manage');
        $devices = $this->deviceManager();

        $statuses = [];
        foreach ($devices->all(false) as $slug => $device) {
            $statuses[$slug] = $device->id > 0 ? $device->lastStatus : [];
        }

        return $this->view('admin/gate/devices', [
            'title'   => 'Устройства',
            'devices' => array_values($devices->all(false)),
            'statuses' => $statuses,
            'drivers' => [
                'basip'      => 'BAS-IP Camdroid (/api/v0)',
                'http-relay' => 'Универсальное HTTP-реле',
                'dryrun'     => 'Эмулятор (без железа)',
            ],
            'defaultSlug' => $devices->default()?->slug,
            'configDevice' => $this->config()->section('device'),
            'channels'    => (array) $this->config()->get('device.lock.channels', [1 => 'Основной']),
        ]);
    }

    public function storeDevice(Request $request): Response
    {
        $this->requirePermission('devices.manage');
        $id = $this->deviceManager()->save($this->devicePayload($request), null);
        $this->auditService()->info('device.create', $this->requireUser(), 'device', (string) $id, $request->str('name'), $request);

        return $this->respond($request, true, 'Устройство добавлено.', '/admin/gate/devices');
    }

    public function updateDevice(Request $request, string $id = ''): Response
    {
        $this->requirePermission('devices.manage');
        $this->deviceManager()->save($this->devicePayload($request), (int) $id);
        $this->auditService()->info('device.update', $this->requireUser(), 'device', (string) $id, $request->str('name'), $request);

        return $this->respond($request, true, 'Устройство сохранено.', '/admin/gate/devices');
    }

    public function deleteDevice(Request $request, string $id = ''): Response
    {
        $this->requirePermission('devices.manage');
        $ok = $this->deviceManager()->delete((int) $id);
        $this->auditService()->info('device.delete', $this->requireUser(), 'device', (string) $id, null, $request);

        return $this->respond($request, $ok, $ok ? 'Устройство удалено.' : 'Не удалось удалить.', '/admin/gate/devices');
    }

    public function testDevice(Request $request, string $id = ''): Response
    {
        $this->requirePermission('devices.manage');
        $devices = $this->deviceManager();
        $device = $id !== '' ? $devices->find($id) : $devices->default();
        if ($device === null) {
            return $this->jsonError('Устройство не найдено.', 404);
        }

        $driver = $devices->driver($device);
        $ping = $driver->ping();
        $status = $ping->ok ? $driver->status() : $ping;
        $time = $ping->ok ? $driver->time() : null;
        $devices->recordStatus($device, $status);

        $this->auditService()->info('device.test', $this->requireUser(), 'device', $device->slug, $device->label(), $request, ['ok' => $ping->ok]);

        return $this->json([
            'ok' => $ping->ok,
            'device' => $device->label(),
            'driver' => $device->driverLabel(),
            'ping' => $ping->toArray(),
            'status' => $status->toArray(),
            'time' => $time?->toArray(),
            'message' => $ping->ok ? 'Устройство отвечает.' : $ping->message,
        ], $ping->ok ? 200 : 502);
    }

    /** @return array<string, mixed> */
    private function devicePayload(Request $request): array
    {
        return [
            'name'     => $request->str('name', 'Шлагбаум'),
            'slug'     => $request->str('slug'),
            'driver'   => $request->str('driver', 'basip'),
            'base_url' => $request->str('base_url'),
            'login'    => $request->str('login'),
            'password' => (string) $request->input('password', ''),
            'channel'  => $request->int('channel', 1) ?: 1,
            'location' => $request->str('location'),
            'is_active' => $request->bool('is_active', true),
            'is_default' => $request->bool('is_default'),
            'notes'    => $request->str('notes'),
            'options'  => $this->parseOptions((string) $request->input('options', '')),
        ];
    }

    /** @return array<string, mixed> */
    private function parseOptions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Accept a simple `key=value` per line form as well.
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            \BasIp\Support\Arr::set($out, trim($key), trim($value));
        }

        return $out;
    }

    /* ─────────────────────────── schedules ─────────────────────────── */

    public function schedules(Request $request): Response
    {
        $this->requirePermission('schedules.manage');

        return $this->view('admin/gate/schedules', [
            'title'     => 'Расписания доступа',
            'schedules' => $this->db()->table('access_schedules')->orderBy('priority', 'desc')->orderBy('id')->get(),
            'residents' => $this->residentRepo()->scopeActive()->select('residents.id', 'residents.full_name', 'residents.apartment')->orderBy('residents.apartment_no')->limit(2000)->get(),
            'days'      => [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'],
            'quietHours' => [
                'enabled' => $this->settingsService()->bool('gate.quiet_hours_enabled', false),
                'start'   => $this->settingsService()->string('gate.quiet_hours_start', '23:00'),
                'end'     => $this->settingsService()->string('gate.quiet_hours_end', '07:00'),
                'active'  => $this->gate()->isQuietHours(),
            ],
        ]);
    }

    public function storeSchedule(Request $request): Response
    {
        $user = $this->requirePermission('schedules.manage');
        $id = $request->int('id') ?: null;

        $days = $request->array('days');
        if ($days === []) {
            $days = ['1', '2', '3', '4', '5', '6', '7'];
        }
        $start = $request->str('start_time', '00:00');
        $end   = $request->str('end_time', '23:59');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
            return $this->respond($request, false, 'Время указано неверно (формат ЧЧ:ММ).', '/admin/gate/schedules');
        }

        $row = [
            'name'        => mb_substr($request->str('name', 'Расписание'), 0, 120),
            'resident_id' => $request->filled('resident_id') ? $request->int('resident_id') : null,
            'days'        => implode(',', array_map('intval', $days)),
            'start_time'  => $start,
            'end_time'    => $end,
            'effect'      => $request->str('effect', 'allow') === 'deny' ? 'deny' : 'allow',
            'priority'    => $request->int('priority', 0),
            'is_active'   => $request->bool('is_active', true) ? 1 : 0,
            'notes'       => $request->str('notes') ?: null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        if ($id !== null) {
            $this->db()->table('access_schedules')->where('id', '=', $id)->update($row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $id = $this->db()->table('access_schedules')->insertGetId($row);
        }

        // Quiet hours live in settings, edited from the same screen.
        if ($request->has('quiet_enabled')) {
            $this->settingsService()->set('gate.quiet_hours_enabled', $request->bool('quiet_enabled'), $user->login);
            $this->settingsService()->set('gate.quiet_hours_start', $request->str('quiet_start', '23:00'), $user->login);
            $this->settingsService()->set('gate.quiet_hours_end', $request->str('quiet_end', '07:00'), $user->login);
            $this->settingsService()->set('gate.allow_guest_at_night', $request->bool('quiet_allow_guest', true), $user->login);
        }

        $this->auditService()->info($id !== null ? 'schedule.update' : 'schedule.create', $user, 'schedule', (string) $id, $row['name'], $request);

        return $this->respond($request, true, 'Расписание сохранено.', '/admin/gate/schedules');
    }

    public function deleteSchedule(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('schedules.manage');
        $ok = $this->db()->table('access_schedules')->where('id', '=', (int) $id)->delete() > 0;
        $this->auditService()->info('schedule.delete', $user, 'schedule', (string) $id, null, $request);

        return $this->respond($request, $ok, $ok ? 'Расписание удалено.' : 'Не найдено.', '/admin/gate/schedules');
    }
}
