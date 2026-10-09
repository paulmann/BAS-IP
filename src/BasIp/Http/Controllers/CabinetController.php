<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\Invoice;
use BasIp\Http\Controller;
use BasIp\Services\BillingService;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Services\ResidentService;
use BasIp\Support\Money;

/**
 * Resident self-service cabinet: open the barrier, issue a 12-hour guest pass,
 * see invoices/payments, review one's own gate history and keep contacts/cars
 * up to date.
 */
final class CabinetController extends Controller
{
    private function residentOrFail()
    {
        $user = $this->requireUser();
        $residentId = $user->residentId;

        // Staff may preview any resident's cabinet via ?as=<id>.
        $request = $this->request();
        if ($user->isStaff() && $request->filled('as')) {
            $residentId = $request->int('as');
        }

        if ($residentId === null) {
            return null;
        }
        $resident = $this->residentRepo()->findById($residentId);
        if ($resident === null) {
            $this->notFound('Карточка жителя не найдена.');
        }

        return $resident;
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $resident = $this->residentOrFail();

        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);

        $statement = $resident !== null ? $billing->residentStatement($resident->id) : null;
        $invoices = $resident !== null
            ? $billing->invoices(['resident_id' => $resident->id], 10, 1)->items()
            : [];

        return $this->view('cabinet/index', [
            'title'       => 'Личный кабинет',
            'resident'    => $resident,
            'statement'   => $statement,
            'invoices'    => $invoices,
            'passes'      => $resident !== null ? $guests->activeForResident($resident->id) : [],
            'passesUsed'  => $resident !== null ? $guests->countCreatedToday($resident->id) : 0,
            'passesLimit' => $this->settingsService()->int('guest.max_per_resident', 5),
            'dailyLimit'  => $this->config()->int('guest.rate_limit_per_resident_day', 10),
            'recent'      => $resident !== null ? $gate->eventsForResident($resident->id, 10) : [],
            'cars'        => $resident !== null ? $this->residentRepo()->rawCarsFor($resident->id) : [],
            'contacts'    => $resident !== null ? $this->residentRepo()->contactsFor($resident->id) : [],
            'canOpen'     => $resident?->canOpenGate() ?? false,
            'canGuest'    => ($resident?->allowGuestPass ?? false) && $this->settingsService()->bool('guest.allow_self_service', true),
            'guestPresets' => $this->config()->list('guest.ttl_presets_hours'),
            'defaultTtl'  => $this->settingsService()->int('guest.default_ttl_hours', 12),
            'requirePlate' => $this->settingsService()->bool('guest.require_plate', false),
            'currency'    => $this->config()->string('billing.currency_symbol', '₽'),
            'impersonating' => $user->isStaff() && $request->filled('as'),
        ]);
    }

    public function guestList(Request $request): Response
    {
        $resident = $this->residentOrFail();
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);

        return $this->view('cabinet/guest', [
            'title'  => 'Гостевые доступы',
            'resident' => $resident,
            'passes' => $guests->list([
                'resident_id' => $resident?->id,
                'status' => $request->str('status', 'all'),
            ], $this->perPage($request), $this->page($request)),
            'activeCount' => $resident !== null ? $guests->countActiveFor($resident->id) : 0,
            'todayCount'  => $resident !== null ? $guests->countCreatedToday($resident->id) : 0,
            'limit'       => $this->settingsService()->int('guest.max_per_resident', 5),
            'dailyLimit'  => $this->config()->int('guest.rate_limit_per_resident_day', 10),
            'presets'     => $this->config()->list('guest.ttl_presets_hours'),
            'defaultTtl'  => $this->settingsService()->int('guest.default_ttl_hours', 12),
            'requirePlate' => $this->settingsService()->bool('guest.require_plate', false),
        ]);
    }

    /** Issue a guest pass (12 hours by default). Every issue is logged. */
    public function createGuest(Request $request): Response
    {
        $user = $this->requireUser();
        $resident = $this->residentOrFail();
        if ($resident === null) {
            return $this->jsonError('Личный кабинет не привязан к карточке жителя.', 403);
        }

        // Staff creating on behalf of somebody else must pass resident_id explicitly.
        $residentId = $resident->id;
        if ($user->isStaff() && $request->filled('resident_id')) {
            $residentId = $request->int('resident_id');
        } elseif (!$user->isStaff() && $user->residentId !== $resident->id) {
            return $this->jsonError('Нельзя создавать гостевые доступы для другого жителя.', 403);
        }

        if (!$this->settingsService()->bool('guest.allow_self_service', true) && !$user->isStaff()) {
            return $this->jsonError('Самостоятельная выдача гостевых доступов отключена.', 403);
        }

        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        $result = $guests->create([
            'resident_id'  => $residentId,
            'ttl_hours'    => $request->int('ttl_hours') ?: $this->settingsService()->int('guest.default_ttl_hours', 12),
            'guest_name'   => $request->str('guest_name'),
            'guest_phone'  => $request->str('guest_phone'),
            'car_plate'    => $request->str('car_plate'),
            'purpose'      => $request->str('purpose'),
            'max_uses'     => $request->int('max_uses'),
            'notify'       => $request->bool('notify', true),
            'notify_guest' => $request->bool('notify_guest', false),
            'notify_on_use' => $request->bool('notify_on_use', false),
            'link_base'    => rtrim($this->config()->string('app.url', ''), '/') ?: ($request->scheme . '://' . $request->header('host')),
        ], $user, $request);

        if (empty($result['ok'])) {
            return $request->expectsJson()
                ? $this->jsonError((string) ($result['error'] ?? 'Ошибка'))
                : $this->flashAndBack($request, (string) ($result['error'] ?? 'Ошибка'), false);
        }

        if ($request->expectsJson()) {
            return $this->json([
                'ok'     => true,
                'id'     => $result['id'],
                'code'   => $result['code'],
                'pin'    => $result['pin'],
                'link'   => $result['link'],
                'expires_at' => $result['pass']?->validUntil,
                'message' => 'Гостевой доступ создан.',
            ]);
        }

        $this->session()->set('guest_pass_just_created', [
            'code' => $result['code'], 'pin' => $result['pin'], 'link' => $result['link'],
            'expires_at' => $result['pass']?->validUntil, 'id' => $result['id'],
        ]);
        $this->flashSuccess('Гостевой доступ создан. Действует до ' . ($result['pass']?->validUntil ?? ''));

        return $this->redirect($user->isStaff() ? '/admin/guest' : '/cabinet/guest');
    }

    public function revokeGuest(Request $request, string $id = ''): Response
    {
        $user = $this->requireUser();
        $resident = $this->residentOrFail();
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);

        $pass = $guests->find((int) $id);
        if ($pass === null) {
            return $this->respond($request, false, 'Гостевой доступ не найден.', '/cabinet/guest');
        }
        // A resident may only revoke their own passes.
        if (!$user->isStaff() && $pass->residentId !== $resident?->id) {
            return $this->respond($request, false, 'Это чужой гостевой доступ.', '/cabinet/guest');
        }

        $ok = $guests->revoke((int) $id, $request->str('reason') ?: 'Отозван владельцем', $user, $request);

        return $this->respond($request, $ok, $ok ? 'Гостевой доступ отозван.' : 'Не удалось отозвать доступ.', $user->isStaff() ? '/admin/guest' : '/cabinet/guest');
    }

    public function payments(Request $request): Response
    {
        $resident = $this->residentOrFail();
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);

        $invoices = $resident !== null ? $billing->invoices(['resident_id' => $resident->id], 50, $this->page($request)) : null;
        $payments = $resident !== null ? $billing->paymentsForResident($resident->id) : [];

        return $this->view('cabinet/payments', [
            'title'     => 'Мои оплаты',
            'resident'  => $resident,
            'invoices'  => $invoices,
            'payments'  => $payments,
            'statement' => $resident !== null ? $billing->residentStatement($resident->id) : null,
            'history'   => $resident !== null ? $billing->historyFor($resident->id, 50) : [],
            'payLink'   => $this->settingsService()->string('billing.pay_link', ''),
            'currency'  => $this->config()->string('billing.currency_symbol', '₽'),
        ]);
    }

    public function log(Request $request): Response
    {
        $resident = $this->residentOrFail();
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);

        $events = $resident !== null
            ? $gate->events(['resident_id' => $resident->id], $this->perPage($request, 25), $this->page($request))
            : null;

        return $this->view('cabinet/log', [
            'title'    => 'История открытий',
            'resident' => $resident,
            'events'   => $events,
            'query'    => $request->query,
        ]);
    }

    public function profile(Request $request): Response
    {
        $resident = $this->residentOrFail();

        return $this->view('cabinet/profile', [
            'title'    => 'Мои данные',
            'resident' => $resident,
            'contacts' => $resident !== null ? $this->residentRepo()->contactsFor($resident->id) : [],
            'cars'     => $resident !== null ? $this->residentRepo()->rawCarsFor($resident->id) : [],
            'editable' => $this->config()->bool('cabinet.allow_self_edit', true),
        ]);
    }

    /** Residents may maintain their own contacts and cars (audited, whitelisted fields only). */
    public function saveProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $resident = $this->residentOrFail();
        if ($resident === null) {
            return $this->jsonError('Карточка жителя не найдена.', 404);
        }
        if (!$user->isStaff() && !$this->config()->bool('cabinet.allow_self_edit', true)) {
            return $this->respond($request, false, 'Самостоятельное редактирование отключено.', '/cabinet/profile');
        }

        /** @var ResidentService $service */
        $service = $this->svc(ResidentService::class);

        $payload = [];
        if ($request->has('contacts')) {
            $payload['contacts'] = $this->sanitizeContacts($request->array('contacts'), $resident);
        }
        if ($request->has('cars')) {
            $payload['cars'] = $this->sanitizeCars($request->array('cars'), $resident);
        }
        if ($payload === []) {
            return $this->respond($request, false, 'Нет данных для сохранения.', '/cabinet/profile');
        }

        $result = $service->update($resident->id, $payload, $user, $request);
        $ok = (bool) ($result['ok'] ?? false);
        $message = $ok ? 'Данные сохранены.' : implode(' ', $result['errors']['_'] ?? ['Не удалось сохранить данные.']);

        return $this->respond($request, $ok, $message, '/cabinet/profile');
    }

    /** @param array<int, mixed> $raw */
    private function sanitizeContacts(array $raw, $resident): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'phone');
            if (!in_array($type, ['phone', 'email', 'telegram', 'whatsapp'], true)) {
                continue;
            }
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[] = [
                'type' => $type,
                'value' => $value,
                'label' => trim((string) ($item['label'] ?? '')),
                'is_primary' => (bool) ($item['is_primary'] ?? false),
                'notify' => (bool) ($item['notify'] ?? true),
            ];
        }

        // Always keep the existing SIP/other entries a resident cannot edit.
        foreach ($this->residentRepo()->contactsFor($resident->id) as $existing) {
            if (!in_array((string) $existing['type'], ['phone', 'email', 'telegram', 'whatsapp'], true)) {
                $out[] = $existing;
            }
        }

        return $out;
    }

    /** @param array<int, mixed> $raw */
    private function sanitizeCars(array $raw, $resident): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $plate = trim((string) ($item['plate'] ?? ''));
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
                'type'  => 'car',
                'is_active' => true,
                'notes' => trim((string) ($item['notes'] ?? '')),
            ];
        }
        // Preserve RFID tags set by the admin.
        $existingTags = [];
        foreach ($this->residentRepo()->rawCarsFor($resident->id) as $car) {
            if (!empty($car['rfid_tag'])) {
                $existingTags[\BasIp\Support\Plate::normalize((string) $car['plate'])] = $car['rfid_tag'];
            }
        }
        foreach ($out as $index => $car) {
            $key = \BasIp\Support\Plate::normalize((string) $car['plate']);
            if (isset($existingTags[$key])) {
                $out[$index]['rfid_tag'] = $existingTags[$key];
            }
        }

        return $out;
    }

    private function flashAndBack(Request $request, string $message, bool $ok): Response
    {
        $ok ? $this->flashSuccess($message) : $this->flashError($message);

        return $this->redirect('/cabinet');
    }
}
