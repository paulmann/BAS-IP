<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\GateEvent;
use BasIp\Http\Controller;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;

/**
 * Guest-access administration: issue on behalf of any resident, revoke,
 * extend, review the log and export.
 */
final class GuestController extends Controller
{
    private function passes(): GuestPassService
    {
        return $this->svc(GuestPassService::class);
    }

    public function index(Request $request): Response
    {
        $this->requirePermission('guest.view');
        $passes = $this->passes();

        $filters = $this->filters($request, ['q', 'status', 'resident_id', 'from', 'to', 'only_active_residents']);
        $filters['status'] = $request->str('status', 'active');
        [$sort, $dir] = $this->sorting($request, ['created', 'until', 'uses', 'name'], 'created', 'desc');
        $filters['sort'] = $sort;
        $filters['dir']  = $dir;

        $paginator = $passes->list($filters, $this->perPage($request, 25), $this->page($request));

        // Attach the public link (the raw token is not stored, only its hash,
        // so links can be regenerated only for passes created in this session).
        $justCreated = $this->session()->pull('guest_pass_just_created');

        return $this->view('admin/guest/index', [
            'title'    => 'Гостевые доступы',
            'passes'   => $paginator,
            'filters'  => $filters,
            'query'    => $request->query,
            'stats'    => $passes->stats(30),
            'residents' => $this->residentRepo()->scopeActive()->select('residents.id', 'residents.full_name', 'residents.apartment', 'residents.building')->orderBy('residents.apartment_no')->limit(3000)->get(),
            'presets'  => $this->config()->list('guest.ttl_presets_hours'),
            'defaultTtl' => $this->settingsService()->int('guest.default_ttl_hours', 12),
            'maxTtl'   => $this->config()->int('guest.max_ttl_hours', 72),
            'limit'    => $this->settingsService()->int('guest.max_per_resident', 5),
            'justCreated' => $justCreated,
            'canCreate' => $this->requireUser()->can('guest.create'),
            'canRevoke' => $this->requireUser()->can('guest.revoke'),
            'canOpen'   => $this->requireUser()->can('gate.open'),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->requirePermission('guest.create');
        $passes = $this->passes();

        $residentId = $request->int('resident_id');
        if ($residentId <= 0) {
            return $this->respond($request, false, 'Выберите жителя.', '/admin/guest');
        }

        $result = $passes->create([
            'resident_id'   => $residentId,
            'ttl_hours'     => $request->int('ttl_hours') ?: $this->settingsService()->int('guest.default_ttl_hours', 12),
            'guest_name'    => $request->str('guest_name'),
            'guest_phone'   => $request->str('guest_phone'),
            'car_plate'     => $request->str('car_plate'),
            'purpose'       => $request->str('purpose'),
            'max_uses'      => $request->int('max_uses'),
            'max_guests'    => $request->int('max_guests', 1),
            'valid_from'    => $request->str('valid_from') ?: null,
            'notify'        => $request->bool('notify', true),
            'notify_guest'  => $request->bool('notify_guest', false),
            'notify_on_use' => $request->bool('notify_on_use', false),
            'link_base'     => rtrim($this->config()->string('app.url', ''), '/') ?: ($request->scheme . '://' . $request->header('host')),
        ], $user, $request);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка'), '/admin/guest');
        }

        $this->session()->set('guest_pass_just_created', [
            'id' => $result['id'], 'code' => $result['code'], 'pin' => $result['pin'],
            'link' => $result['link'], 'expires_at' => $result['pass']?->validUntil,
            'resident' => $this->residentRepo()->findById($residentId)?->displayName(),
        ]);

        if ($request->expectsJson()) {
            return $this->json([
                'ok' => true, 'id' => $result['id'], 'code' => $result['code'],
                'pin' => $result['pin'], 'link' => $result['link'],
                'expires_at' => $result['pass']?->validUntil,
                'message' => 'Гостевой доступ создан.',
            ]);
        }

        $this->flashSuccess(sprintf('Гостевой доступ %s создан (до %s).', $result['code'], (string) $result['pass']?->validUntil));

        return $this->redirect('/admin/guest');
    }

    public function revoke(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('guest.revoke');
        $ok = $this->passes()->revoke((int) $id, $request->str('reason', 'Отозван администратором'), $user, $request);

        return $this->respond($request, $ok, $ok ? 'Гостевой доступ отозван.' : 'Не удалось отозвать.', $request->str('redirect', '/admin/guest'));
    }

    public function extend(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('guest.create');
        $hours = max(1, $request->int('hours', 12));
        $ok = $this->passes()->extend((int) $id, $hours, $user, $request);

        return $this->respond($request, $ok, $ok ? sprintf('Доступ продлён на %d ч.', $hours) : 'Не удалось продлить.', '/admin/guest');
    }

    /** Staff opens the barrier against a specific guest pass. */
    public function open(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('gate.open');
        $pass = $this->passes()->find((int) $id);
        if ($pass === null) {
            return $this->jsonError('Гостевой доступ не найден.', 404);
        }

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $resident = $pass->residentId !== null ? $this->residentRepo()->findById($pass->residentId) : null;

        $result = $gate->open([
            'guest_pass'  => $pass,
            'resident'    => $resident,
            'user'        => $user,
            'access_type' => GateEvent::ACCESS_GUEST,
            'source'      => 'admin',
            'device'      => $request->str('device') ?: null,
            'plate'       => $pass->carPlate,
            'note'        => 'Открыто диспетчером по гостевому доступу ' . $pass->code,
        ], $request);

        return $this->json([
            'ok' => $result->ok, 'message' => $result->message,
            'error_code' => $result->errorCode, 'duration' => round($result->durationMs),
        ], $result->ok ? 200 : 422);
    }

    public function export(Request $request): Response
    {
        $this->requirePermission('data.export');
        $filters = $this->filters($request, ['status', 'from', 'to', 'resident_id', 'q']);
        $filters['status'] = $request->str('status', 'all');
        $paginator = $this->passes()->list($filters, 1000, 1);

        $delimiter = (string) $this->config()->string('data.csv_delimiter', ';');
        $rows = [['ID', 'Код', 'Житель', 'Квартира', 'Гость', 'Телефон гостя', 'Авто', 'Цель', 'Создан', 'Действует до', 'Использований', 'Статус', 'Кем создан', 'IP']];

        foreach ($paginator->items() as $pass) {
            $rows[] = [
                (string) $pass->id, $pass->code, (string) ($pass->residentName ?? ''), (string) ($pass->apartment ?? ''),
                (string) ($pass->guestName ?? ''), (string) ($pass->guestPhone ?? ''), $pass->displayPlate(),
                (string) ($pass->purpose ?? ''), (string) ($pass->createdAt ?? ''), (string) ($pass->validUntil ?? ''),
                $pass->usesLabel(), $pass->statusLabel(), $pass->createdByType, (string) ($pass->ipCreated ?? ''),
            ];
        }

        $csv = ($this->config()->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '');
        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(static function ($v) use ($delimiter): string {
                $v = (string) $v;

                return str_contains($v, $delimiter) || str_contains($v, '"') ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, $row)) . "\r\n";
        }

        $this->auditService()->info('data.export.guest_passes', $this->requireUser(), 'guest_passes', null, count($rows) . ' строк', $request, $filters);

        return Response::csv($csv, sprintf('guest-passes-%s.csv', date('Ymd-His')));
    }
}
