<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\Contact;
use BasIp\Domain\Model\GateEvent;
use BasIp\Http\Controller;
use BasIp\Services\BillingService;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Services\ImportExportService;
use BasIp\Services\ResidentService;
use BasIp\Services\UserService;
use BasIp\Support\Money;
use BasIp\Support\Phone;
use BasIp\Support\Plate;

/**
 * The resident database: search, CRUD with nested contacts/cars, toggles,
 * bulk actions, per-resident payments/invoices and CSV export.
 *
 * Every list defaults to **active** residents only — inactive ones never take
 * part in splits or mailings.
 */
final class ResidentsController extends Controller
{
    private const SORTABLE = ['apartment', 'full_name', 'balance', 'debt', 'created_at', 'last_payment', 'last_event', 'is_active'];

    public function index(Request $request): Response
    {
        $this->requirePermission('residents.view');

        $filters = $this->filters($request, [
            'q', 'status', 'building', 'apartment_from', 'apartment_to',
            'tag', 'access', 'has_phone', 'has_email', 'has_car', 'no_contact',
            'created_from', 'created_to',
        ]);
        $filters['status'] = $request->str('status', 'active');
        [$sort, $dir] = $this->sorting($request, self::SORTABLE, 'apartment', 'asc');
        $filters['sort'] = $sort;
        $filters['dir']  = $dir;

        $paginator = $this->residentRepo()->search($filters, $this->perPage($request), $this->page($request));
        /** @var array<int, \BasIp\Domain\Model\Resident> $items */
        $items = $paginator->items();
        $this->residentRepo()->loadRelations($items);

        return $this->view('admin/residents/index', [
            'title'     => 'Жители',
            'residents' => $paginator,
            'filters'   => $filters,
            'query'     => $request->query,
            'buildings' => $this->residentRepo()->buildings(),
            'tags'      => $this->residentRepo()->tags(),
            'counts'    => [
                'all'      => $this->residentRepo()->countAll(),
                'active'   => $this->residentRepo()->countActive(),
                'inactive' => $this->residentRepo()->countInactive(),
                'no_access' => $this->residentRepo()->countWithoutAccess(),
                'deleted'  => $this->residentRepo()->countDeleted(),
                'billable' => $this->residentRepo()->countBillable(),
            ],
            'canEdit'   => $this->requireUser()->can('residents.edit'),
            'canCreate' => $this->requireUser()->can('residents.create'),
            'canGate'   => $this->requireUser()->can('gate.open'),
            'contactTypes' => Contact::TYPES,
            'carTypes'  => ['car', 'suv', 'motorcycle', 'truck', 'ev', 'other'],
            'roles'     => ['owner', 'tenant', 'relative', 'manager', 'other'],
            'perPageOptions' => $this->config()->list('ui.per_page_options'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->requirePermission('residents.create');

        return $this->formView(null, 'Новый житель');
    }

    public function edit(Request $request, string $id = ''): Response
    {
        $this->requirePermission('residents.edit');
        $resident = $this->residentRepo()->findById((int) $id, true);
        if ($resident === null) {
            $this->notFound('Житель не найден.');
        }

        return $this->formView($resident, 'Редактирование: ' . $resident->displayName());
    }

    private function formView(?\BasIp\Domain\Model\Resident $resident, string $title): Response
    {
        $id = $resident?->id ?? 0;

        return $this->view('admin/residents/form', [
            'title'    => $title,
            'resident' => $resident,
            'contacts' => $id > 0 ? $this->residentRepo()->contactsFor($id) : [],
            'cars'     => $id > 0 ? $this->residentRepo()->rawCarsFor($id) : [],
            'contactTypes' => Contact::TYPES,
            'carTypes' => ['car', 'suv', 'motorcycle', 'truck', 'ev', 'other'],
            'roles'    => ['owner', 'tenant', 'relative', 'manager', 'other'],
            'buildings' => $this->residentRepo()->buildings(),
            'existingTags' => $this->residentRepo()->tags(),
            'account'  => $id > 0 ? $this->db()->table('users')->where('resident_id', '=', $id)->whereNull('deleted_at')->first() : null,
            'isNew'    => $resident === null,
        ]);
    }

    public function show(Request $request, string $id = ''): Response
    {
        $this->requirePermission('residents.view');
        $resident = $this->residentRepo()->findById((int) $id, true);
        if ($resident === null) {
            $this->notFound('Житель не найден.');
        }
        $residentId = $resident->id;

        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);

        return $this->view('admin/residents/show', [
            'title'     => $resident->displayName(),
            'resident'  => $resident,
            'contacts'  => $this->residentRepo()->contactsFor($residentId),
            'cars'      => $this->residentRepo()->rawCarsFor($residentId),
            'statement' => $billing->residentStatement($residentId),
            'invoices'  => $billing->invoices(['resident_id' => $residentId], 100, 1)->items(),
            'payments'  => $billing->paymentsForResident($residentId, 50),
            'events'    => $gate->eventsForResident($residentId, 25),
            'passes'    => $guests->list(['resident_id' => $residentId, 'status' => 'all'], 10, 1)->items(),
            'history'   => $billing->historyFor($residentId, 50),
            'audit'     => $this->auditService()->forSubject('resident', $residentId, 30),
            'account'   => $this->db()->table('users')->where('resident_id', '=', $residentId)->whereNull('deleted_at')->first(),
            'currency'  => $this->config()->string('billing.currency_symbol', '₽'),
            'canEdit'   => $this->requireUser()->can('residents.edit'),
            'canGate'   => $this->requireUser()->can('gate.open'),
            'canPayment' => $this->requireUser()->can('billing.payment.record'),
            'canInvoice' => $this->requireUser()->can('billing.invoice.edit'),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->requirePermission('residents.create');
        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);

        $payload = $this->payload($request);
        $result = $service->create($payload, $user, $request);

        if (!$result['ok']) {
            $this->withErrors($result['errors'] ?? [], $request->all());

            return $this->respond($request, false, implode(' ', array_merge(...array_values($result['errors'] ?? [['Ошибка']]))), '/admin/residents/create');
        }

        if ($request->bool('create_account') && $result['id'] !== null) {
            /** @var UserService $users */
            $users = $this->svc(UserService::class);
            $account = $users->ensureResidentAccount((int) $result['id'], $request->str('account_login') ?: null, $request->str('account_password') ?: null, $user);
            if ($account['ok'] && !empty($account['password'])) {
                $this->session()->flash('account_credentials', ['login' => $account['login'], 'password' => $account['password']]);
            }
        }

        $message = 'Житель добавлен.';
        if ($request->bool('create_another')) {
            $this->flashSuccess($message);

            return $this->redirect('/admin/residents/create');
        }

        return $this->respond($request, true, $message, '/admin/residents/' . (int) $result['id']);
    }

    public function update(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('residents.edit');
        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);

        $result = $service->update((int) $id, $this->payload($request), $user, $request);
        if (!$result['ok']) {
            $this->withErrors($result['errors'] ?? [], $request->all());

            return $this->respond($request, false, implode(' ', array_merge(...array_values($result['errors'] ?? [['Ошибка']]))), '/admin/residents/' . (int) $id . '/edit');
        }

        if ($request->bool('sync_account')) {
            /** @var UserService $users */
            $users = $this->svc(UserService::class);
            $users->ensureResidentAccount((int) $id, $request->str('account_login') ?: null, null, $user);
        }

        return $this->respond($request, true, 'Изменения сохранены.', '/admin/residents/' . (int) $id);
    }

    /** Inline toggles from the table (access / active / billed). */
    public function toggle(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('residents.edit');
        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);

        $field = $request->str('field', 'is_active');
        $value = $request->bool('value');
        $residentId = (int) $id;

        $ok = match ($field) {
            'access_granted' => $service->setAccess($residentId, $value, $user, $request),
            'is_billed'      => $service->setBilled($residentId, $value, $user, $request),
            'notify_enabled', 'allow_guest_pass' => $this->toggleSimple($residentId, $field, $value, $user),
            default          => $service->setActive($residentId, $value, $user, $request),
        };

        if ($field === 'is_active' && !$value) {
            // Switching a resident off immediately invalidates their guest passes.
            $this->db()->table('guest_passes')
                ->where('resident_id', '=', $residentId)
                ->where('status', '=', 'active')
                ->update(['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s'), 'revoke_reason' => 'Житель отключён']);
        }

        $label = match ($field) {
            'is_active'      => $value ? 'Житель включён.' : 'Житель отключён.',
            'access_granted' => $value ? 'Доступ разрешён.' : 'Доступ приостановлен.',
            'is_billed'      => $value ? 'Участвует в оплате.' : 'Исключён из оплаты.',
            'notify_enabled' => $value ? 'Уведомления включены.' : 'Уведомления выключены.',
            'allow_guest_pass' => $value ? 'Гостевой доступ разрешён.' : 'Гостевой доступ запрещён.',
            default          => 'Обновлено.',
        };

        return $this->respond($request, (bool) $ok, $label, '/admin/residents');
    }

    private function toggleSimple(int $residentId, string $field, bool $value, $user): bool
    {
        $affected = $this->db()->table('residents')->where('id', '=', $residentId)->update([
            $field => $value ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $user->id,
        ]);
        $this->auditService()->info('resident.toggle.' . $field, $user, 'resident', (string) $residentId, null, null, ['value' => $value]);

        return $affected > 0;
    }

    public function destroy(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('residents.delete');
        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);
        $hard = $request->bool('hard');

        $ok = $service->delete((int) $id, $hard, $user, $request);

        return $this->respond($request, $ok, $ok ? ($hard ? 'Запись удалена безвозвратно.' : 'Житель перемещён в архив.') : 'Не удалось удалить.', '/admin/residents?status=' . ($hard ? 'all' : 'deleted'));
    }

    public function restore(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('residents.restore');
        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);
        $ok = $service->restore((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? 'Житель восстановлен.' : 'Не удалось восстановить.', '/admin/residents?status=deleted');
    }

    /** @param array<int, string> $actions */
    public function bulk(Request $request): Response
    {
        $user = $this->requirePermission('residents.edit');
        $action = $request->str('action');
        $ids = array_map('intval', $request->array('ids'));

        $allowed = [
            'activate' => 'residents.edit', 'deactivate' => 'residents.edit',
            'grant_access' => 'residents.edit', 'revoke_access' => 'residents.edit',
            'bill' => 'billing.charge.issue', 'unbill' => 'billing.charge.issue',
            'delete' => 'residents.delete', 'tag' => 'residents.edit',
            'note' => 'residents.edit', 'recalc_balance' => 'billing.payment.record',
        ];
        if (!isset($allowed[$action])) {
            return $this->respond($request, false, 'Неизвестное действие.', '/admin/residents');
        }
        if (!$user->can((string) $allowed[$action])) {
            return $this->respond($request, false, 'Недостаточно прав для массового действия.', '/admin/residents');
        }
        if ($ids === []) {
            return $this->respond($request, false, 'Не выбрано ни одной записи.', '/admin/residents');
        }

        // Notify is handled separately because it builds messages.
        if ($action === 'notify') {
            return $this->bulkNotify($request, $ids, $user);
        }

        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);
        $result = $service->bulk($action, $ids, [
            'only_active' => $request->bool('only_active', true),
            'tag'  => $request->str('tag'),
            'note' => $request->str('note'),
            'hard' => $request->bool('hard'),
            'mode' => $request->str('tag_mode', 'add'),
        ], $user, $request);

        $message = sprintf('Обработано: %d. Пропущено (неактивные): %d.', $result['affected'], $result['skipped']);

        return $this->respond($request, true, $message, $request->str('redirect', '/admin/residents'), ['affected' => $result['affected'], 'skipped' => $result['skipped']]);
    }

    /** Mass mailing to the selected (active) residents. */
    private function bulkNotify(Request $request, array $ids, $user): Response
    {
        if (!$user->can('notifications.send')) {
            return $this->respond($request, false, 'Недостаточно прав для рассылки.', '/admin/residents');
        }
        $subject = $request->str('subject', 'Информация от управляющего шлагбаумом');
        $body = trim((string) $request->input('message', ''));
        if ($body === '') {
            return $this->respond($request, false, 'Введите текст сообщения.', '/admin/residents');
        }
        $channels = $request->array('channels') ?: $this->settingsService()->json('notify.channels_default', ['log']);

        /** @var \BasIp\Notify\NotificationService $notify */
        $notify = $this->svc(\BasIp\Notify\NotificationService::class);
        /** @var \BasIp\Notify\TemplateRenderer $templates */
        $templates = $this->svc(\BasIp\Notify\TemplateRenderer::class);

        $messages = [];
        $skipped = 0;
        foreach ($this->residentRepo()->findByIds($ids, (bool) $request->bool('only_active', true)) as $resident) {
            if (!$resident->notifyEnabled) {
                $skipped++;
                continue;
            }
            $context = [
                'name' => $resident->shortName(), 'full_name' => $resident->displayName(),
                'apartment' => $resident->apartment, 'building' => (string) $resident->building,
                'balance' => Money::format($resident->balance, $this->config()->string('billing.currency_symbol', '₽')),
                'organisation' => $this->settingsService()->string('ui.organisation_name', $this->config()->string('app.name')),
                'pay_link' => $this->settingsService()->string('billing.pay_link', ''),
                'today' => date('d.m.Y'),
            ];
            $messages[] = \BasIp\Notify\Message::forResident($resident)
                ->template('custom')
                ->subject($templates->interpolate($subject, $context))
                ->body($templates->interpolate($body, $context))
                ->context($context)
                ->channels($channels);
        }

        $batch = $notify->queueBatch($messages, [
            'name' => 'Рассылка по выбранным жителям', 'subject' => $subject, 'message' => $body,
            'channels' => $channels, 'created_by' => $user->id,
            'audience' => ['ids' => $ids, 'only_active' => $request->bool('only_active', true)],
        ]);

        $this->auditService()->record('residents.bulk.notify', $user, [
            'subject_type' => 'batch', 'subject_id' => (string) $batch['id'],
            'meta' => ['queued' => $batch['queued'], 'skipped' => $skipped + $batch['skipped']],
        ], $request);

        return $this->respond(
            $request, true,
            sprintf('В очередь поставлено %d сообщений (пропущено %d).', $batch['queued'], $skipped + $batch['skipped']),
            '/admin/notifications/batches/' . (int) $batch['id']
        );
    }

    /** Open the barrier for a specific resident (from their card or the table). */
    public function openGate(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('gate.open');
        $resident = $this->residentRepo()->findById((int) $id);
        if ($resident === null) {
            return $this->jsonError('Житель не найден.', 404);
        }

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $result = $gate->open([
            'resident' => $resident,
            'user'     => $user,
            'access_type' => GateEvent::ACCESS_REMOTE,
            'source'   => 'admin',
            'device'   => $request->str('device') ?: null,
            'note'     => 'Открыто диспетчером для ' . $resident->displayName(),
            'force'    => $request->bool('force'),
        ], $request);

        return $this->json([
            'ok' => $result->ok, 'message' => $result->message,
            'duration' => round($result->durationMs), 'error_code' => $result->errorCode,
        ], $result->ok ? 200 : 422);
    }

    public function recordPayment(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.payment.record');
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);

        $result = $billing->recordPayment([
            'resident_id' => (int) $id,
            'amount'      => $request->float('amount'),
            'invoice_id'  => $request->filled('invoice_id') ? $request->int('invoice_id') : null,
            'paid_at'     => $request->str('paid_at') ?: null,
            'method'      => $request->str('method', 'transfer'),
            'reference'   => $request->str('reference'),
            'payer_name'  => $request->str('payer_name'),
            'note'        => $request->str('note'),
        ], $user, $request);

        $message = $result['ok']
            ? sprintf('Оплата %s проведена.%s', Money::format($request->float('amount'), $this->config()->string('billing.currency_symbol', '₽')), isset($result['overpay']) && $result['overpay'] > 0.004 ? ' Переплата: ' . Money::format((float) $result['overpay']) : '')
            : (string) ($result['error'] ?? 'Не удалось провести оплату.');

        return $this->respond($request, (bool) $result['ok'], $message, $request->str('redirect', '/admin/residents/' . (int) $id));
    }

    public function createInvoice(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.invoice.edit');
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);

        $result = $billing->createManualInvoice([
            'resident_id'  => (int) $id,
            'amount'       => $request->float('amount'),
            'period_label' => $request->str('period_label'),
            'due_date'     => $request->str('due_date') ?: null,
            'note'         => $request->str('note'),
            'pay_link'     => $request->str('pay_link') ?: null,
        ], $user, $request);

        return $this->respond($request, (bool) ($result['ok'] ?? false), $result['ok'] ? 'Счёт создан.' : (string) ($result['error'] ?? 'Ошибка'), $request->str('redirect', '/admin/residents/' . (int) $id));
    }

    public function ensureAccount(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('users.create');
        /** @var UserService $users */
        $users = $this->svc(UserService::class);
        $result = $users->ensureResidentAccount((int) $id, $request->str('login') ?: null, $request->str('password') ?: null, $user);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка'), '/admin/residents/' . (int) $id);
        }

        $password = isset($result['password']) && $result['password'] !== '' ? $result['password'] : null;
        $message = $password !== null
            ? sprintf('Учётная запись «%s» создана. Пароль: %s', $result['login'], $password)
            : sprintf('Учётная запись «%s» уже существует.', $result['login']);

        $this->flashSuccess($message);

        return $this->redirect('/admin/residents/' . (int) $id);
    }

    public function exportCsv(Request $request): Response
    {
        $this->requirePermission('data.export');
        /** @var ImportExportService $io */
        $io = $this->svc(ImportExportService::class);

        $filters = $this->filters($request, ['q', 'status', 'building', 'apartment_from', 'apartment_to', 'tag']);
        $filters['status'] = $request->str('status', 'active');

        $result = $io->exportResidents($filters, [
            'format'  => $request->str('format', 'csv'),
            'include' => $request->array('include') ?: ['contacts', 'cars', 'payments'],
            'scope'   => $request->str('scope', 'filtered'),
        ]);

        $this->auditService()->info('data.export.residents', $this->requireUser(), 'export_job', (string) $result['job_id'], $result['filename'], $request, ['rows' => $result['rows']]);

        return Response::file($result['path'], $result['filename']);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = $request->only([
            'last_name', 'first_name', 'patronymic', 'full_name',
            'apartment', 'apartment_no', 'building', 'entrance', 'floor',
            'area', 'persons', 'share', 'role_in_home',
            'phone', 'email', 'notes', 'external_id',
        ]);

        foreach (['access_granted', 'is_active', 'is_billed', 'allow_guest_pass', 'notify_enabled'] as $flag) {
            $payload[$flag] = $request->bool($flag, $flag !== 'is_billed' ? true : true);
        }

        $tags = $request->input('tags');
        $payload['tags'] = is_array($tags) ? $tags : (string) ($tags ?? '');

        // Nested contacts & cars come as indexed arrays from the repeatable rows.
        $payload['contacts'] = $this->normalizeContacts($request->array('contacts'));
        $payload['cars'] = $this->normalizeCars($request->array('cars'));

        return $payload;
    }

    /** @param array<int, mixed> $raw @return array<int, array<string, mixed>> */
    private function normalizeContacts(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string) ($item['type'] ?? 'phone')));
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '' || !in_array($type, Contact::TYPES, true)) {
                continue;
            }
            if ($type === 'phone' && !Phone::isValid($value)) {
                continue;
            }
            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'value' => $value,
                'label' => trim((string) ($item['label'] ?? '')),
                'is_primary' => (bool) ($item['is_primary'] ?? false),
                'notify' => (bool) ($item['notify'] ?? true),
                'verified' => (bool) ($item['verified'] ?? false),
            ];
        }

        return $out;
    }

    /** @param array<int, mixed> $raw @return array<int, array<string, mixed>> */
    private function normalizeCars(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $plate = Plate::normalize(trim((string) ($item['plate'] ?? '')));
            $brand = trim((string) ($item['brand'] ?? ''));
            if ($plate === '' && $brand === '') {
                continue;
            }
            $out[] = [
                'plate' => $plate,
                'brand' => $brand,
                'model' => trim((string) ($item['model'] ?? '')),
                'color' => trim((string) ($item['color'] ?? '')),
                'year'  => $item['year'] ?? null,
                'type'  => in_array($item['type'] ?? 'car', ['car', 'suv', 'motorcycle', 'truck', 'ev', 'other'], true) ? $item['type'] : 'car',
                'rfid_tag' => trim((string) ($item['rfid_tag'] ?? '')),
                'sticker' => trim((string) ($item['sticker'] ?? '')),
                'is_active' => (bool) ($item['is_active'] ?? true),
                'valid_from' => $item['valid_from'] ?? null,
                'valid_until' => $item['valid_until'] ?? null,
                'notes' => trim((string) ($item['notes'] ?? '')),
            ];
        }

        return $out;
    }
}
