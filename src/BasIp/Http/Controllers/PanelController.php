<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Device\DeviceManager;
use BasIp\Domain\Model\GateEvent;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Http\Controller;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;

/**
 * The operator/resident control panel: live status, the big «Открыть» button,
 * camera snapshot, device time, SIP toggle and recent gate activity.
 *
 * This is the successor of the original `bas-ip.php`, but authenticated,
 * role-aware and fully logged.
 */
final class PanelController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        /** @var DeviceManager $devices */
        $devices = $this->svc(DeviceManager::class);
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);

        $device = $devices->resolve($request->str('device') ?: null);
        $status = $gate->status($device?->slug);
        $resident = $user->residentId !== null ? $this->residentRepo()->findById($user->residentId) : null;

        $recent = $user->isStaff()
            ? $gate->recentEvents(12)
            : ($resident !== null ? $gate->eventsForResident($resident->id, 12) : []);

        $myPasses = $resident !== null ? $guests->activeForResident($resident->id) : [];

        return $this->view('panel/index', [
            'title'      => 'Панель управления',
            'device'     => $device,
            'devices'    => array_values($devices->all()),
            'status'     => $status,
            'recent'     => $recent,
            'resident'   => $resident,
            'myPasses'   => $myPasses,
            'canOpen'    => $user->can('gate.open'),
            'canReboot'  => $user->can('gate.reboot'),
            'canSip'     => $user->can('gate.device.control'),
            'canGuest'   => $user->can('guest.create') && ($resident === null || $resident->allowGuestPass),
            'cameraEnabled' => $this->config()->bool('device.camera.enabled', true) && $this->settingsService()->bool('ui.camera_enabled', true),
            'cameraRefresh' => $this->config()->int('ui.camera_refresh_seconds', 5),
            'statusRefresh' => $this->config()->int('ui.status_refresh_seconds', 300),
            'cooldown'   => $this->settingsService()->int('gate.open_cooldown', $this->config()->int('device.lock.cooldown_seconds', 3)),
            'guestPresets' => $this->config()->list('guest.ttl_presets_hours'),
            'defaultTtl' => $this->settingsService()->int('guest.default_ttl_hours', $this->config()->int('guest.default_ttl_hours', 12)),
            'quietHours' => $this->settingsService()->bool('gate.quiet_hours_enabled', false) ? [
                'start' => $this->settingsService()->string('gate.quiet_hours_start', '23:00'),
                'end'   => $this->settingsService()->string('gate.quiet_hours_end', '07:00'),
                'now'   => $gate->isQuietHours(),
            ] : null,
        ]);
    }

    public function open(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->can('gate.open')) {
            return $this->jsonError('Недостаточно прав для открытия шлагбаума.', 403);
        }

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);

        $resident = null;
        if ($request->filled('resident_id') && $user->isStaff()) {
            $resident = $this->residentRepo()->findById($request->int('resident_id'));
            if ($resident === null) {
                return $this->jsonError('Житель не найден.', 404);
            }
        } elseif ($user->residentId !== null) {
            $resident = $this->residentRepo()->findById($user->residentId);
        }

        // Staff opening "on behalf of" a resident still logs whose access it was.
        $accessType = $resident !== null && !$user->isStaff() ? GateEvent::ACCESS_OWN : ($user->isStaff() ? GateEvent::ACCESS_MANUAL : GateEvent::ACCESS_OWN);
        if ($request->filled('access_type')) {
            $requested = $request->str('access_type');
            if (in_array($requested, [GateEvent::ACCESS_OWN, GateEvent::ACCESS_EMERGENCY, GateEvent::ACCESS_REMOTE], true)) {
                $accessType = $requested;
            }
        }

        $result = $gate->open([
            'resident'    => $resident,
            'user'        => $user,
            'access_type' => $accessType,
            'source'      => $user->isStaff() ? 'admin' : 'cabinet',
            'device'      => $request->str('device') ?: null,
            'channel'     => $request->int('channel') ?: null,
            'note'        => $request->str('note'),
            'force'       => $user->isStaff() && $request->bool('force'),
        ], $request);

        $payload = [
            'ok'       => $result->ok,
            'message'  => $result->message,
            'duration' => round($result->durationMs),
            'data'     => $result->data,
            'error_code' => $result->errorCode,
        ];

        return $this->json($payload, $result->ok ? 200 : 422);
    }

    public function close(Request $request): Response
    {
        $user = $this->requirePermission('gate.close');
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $result = $gate->close(['user' => $user, 'device' => $request->str('device') ?: null, 'source' => 'admin'], $request);

        return $this->json(['ok' => $result->ok, 'message' => $result->message], $result->ok ? 200 : 422);
    }

    public function status(Request $request): Response
    {
        $this->requireUser();
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        /** @var DeviceManager $devices */
        $devices = $this->svc(DeviceManager::class);
        $device = $devices->resolve($request->str('device') ?: null);

        $result = $gate->status($device?->slug, !$request->bool('fresh'));
        $time = $request->bool('with_time') ? $gate->time($device?->slug) : null;

        return $this->json([
            'ok'      => $result->ok,
            'device'  => $device?->label(),
            'status'  => $result->data,
            'message' => $result->message,
            'ms'      => round($result->durationMs, 1),
            'time'    => $time?->data,
            'server_time' => date('c'),
        ]);
    }

    /** Streams a JPEG snapshot proxied from the panel (no CORS/credential leakage). */
    public function snapshot(Request $request): Response
    {
        $this->requireUser();
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $jpeg = $gate->snapshot($request->str('device') ?: null);

        if ($jpeg === null || $jpeg === '') {
            return Response::noContent(404);
        }

        return (new Response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=' . max(1, $this->config()->int('device.camera.cache_ttl', 2)),
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }
}
