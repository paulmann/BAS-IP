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
use Throwable;

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

    /* ─────────────────────────── resident remote ─────────────────────────── */

    /**
     * The full-screen «gate remote»: one enormous OPEN button (≈95% of the
     * screen on a phone), the live camera of the selected gate, status.
     * Deliberately minimal — it must be usable one-handed, in the dark,
     * in the rain, with a phone in the car mount.
     */
    public function remote(Request $request): Response
    {
        $user = $this->requireUser();

        /** @var DeviceManager $devices */
        $devices = $this->svc(DeviceManager::class);

        return $this->view('remote/index', [
            'title'       => 'Пульт шлагбаума',
            'layout'      => 'remote',
            'user'        => $user,
            'devices'     => array_values($devices->all()),
            'cameras'     => $this->availableCameras(),
            'canClose'    => $user->can('gate.close'),
            'cooldown'    => $this->settingsService()->int('gate.open_cooldown', $this->config()->int('device.lock.cooldown_seconds', 3)),
            'cameraRefresh' => $this->config()->int('ui.camera_refresh_seconds', 5),
            'organisation' => $this->settingsService()->string('ui.organisation_name', $this->config()->string('app.name', 'BAS-IP')),
        ]);
    }

    /** JSON: cameras available to the current user (each is bound to a gate device). */
    public function cameras(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->can('gate.open')) {
            return $this->json(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
        }

        return $this->json(['ok' => true, 'data' => $this->availableCameras()]);
    }

    /**
     * Every active device with its camera enabled → one selectable camera.
     * The camera is bound to the gate: opening always targets the same
     * device the user is watching.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableCameras(): array
    {
        /** @var DeviceManager $devices */
        $devices = $this->svc(DeviceManager::class);

        $out = [];
        foreach ($devices->all() as $device) {
            $options = $devices->optionsFor($device);
            $camera = array_merge(
                ['enabled' => true, 'mode' => $this->config()->string('device.camera.mode', 'snapshot')],
                (array) ($options['camera'] ?? []),
            );
            if (!($camera['enabled'] ?? true) || ($camera['mode'] ?? 'off') === 'off') {
                continue;
            }
            $mode = (string) $camera['mode'];
            $streamUrl = trim((string) ($camera['stream_url'] ?? ''));

            // Direct mode only makes sense with an explicit stream URL that
            // clients can actually reach; otherwise fall back to the proxy.
            $direct = $mode === 'mjpeg' && $streamUrl !== '' ? $streamUrl : null;
            $proxy  = $mode !== 'snapshot' && $direct === null;

            $out[] = [
                'device'     => $device->slug,
                'name'       => trim($device->name . ($device->location !== null && $device->location !== '' ? ' — ' . $device->location : '')),
                'mode'       => $direct !== null ? 'mjpeg' : ($proxy ? 'proxy' : 'snapshot'),
                'frame_url'  => $this->request()->url('/panel/snapshot') . '?device=' . urlencode($device->slug),
                'stream_url' => $direct ?? ($proxy ? $this->request()->url('/panel/camera/stream') . '?device=' . urlencode($device->slug) : null),
                'refresh'    => max(1, (int) ($camera['cache_ttl'] ?? 2)),
            ];
        }

        return $out;
    }

    /**
     * MJPEG proxy: the browser talks only to the app, the app talks to the
     * camera. Two sources:
     *   • `camera.stream_url` set → raw pass-through of the upstream stream;
     *   • otherwise (BAS-IP panels) → frames are pulled via the driver's
     *     snapshot endpoint and re-emitted as multipart/x-mixed-replace.
     * Bounded by `?seconds=` (default 120, max 300) — the client reconnects.
     */
    public function cameraStream(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->can('gate.open')) {
            return $this->json(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
        }

        /** @var DeviceManager $devices */
        $devices = $this->svc(DeviceManager::class);
        $device = $devices->resolve($request->str('device') ?: null);
        if ($device === null) {
            throw new \BasIp\Http\RoutingException('Устройство не найдено.', 404);
        }
        $options = $devices->optionsFor($device);
        $camera = array_merge(['enabled' => true, 'mode' => 'snapshot'], (array) ($options['camera'] ?? []));
        if (!($camera['enabled'] ?? true) || ($camera['mode'] ?? '') === 'off') {
            throw new \BasIp\Http\RoutingException('Камера этого устройства отключена.', 404);
        }

        $maxSeconds = max(10, min(300, $request->int('seconds', 120)));
        $streamUrl  = trim((string) ($camera['stream_url'] ?? ''));
        $slug       = $device->slug;
        $gate       = $this->svc(GateService::class);
        $frameDelay = max(1, (int) ($camera['cache_ttl'] ?? 2));

        return Response::streamed(200, [
            'Content-Type' => 'multipart/x-mixed-replace; boundary=basipframe',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
            'Pragma' => 'no-cache',
        ], static function () use ($streamUrl, $slug, $gate, $maxSeconds, $frameDelay): void {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $deadline = time() + $maxSeconds;

            if ($streamUrl !== '') {
                // Raw pass-through of an upstream MJPEG stream.
                $context = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: BAS-IP-Gate-Manager/2.0\r\n"]]);
                $upstream = @fopen($streamUrl, 'rb', false, $context);
                if ($upstream === false) {
                    return;
                }
                while (!feof($upstream) && !connection_aborted() && time() < $deadline) {
                    $chunk = fread($upstream, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
                fclose($upstream);

                return;
            }

            // Synthesise a stream from panel snapshots (BAS-IP without MJPEG URL).
            while (!connection_aborted() && time() < $deadline) {
                $started = microtime(true);
                $jpeg = null;
                try {
                    $jpeg = $gate->snapshot($slug);
                } catch (Throwable) {
                    $jpeg = null;
                }
                if (is_string($jpeg) && $jpeg !== '') {
                    echo "--basipframe\r\nContent-Type: image/jpeg\r\nContent-Length: " . strlen($jpeg) . "\r\n\r\n" . $jpeg . "\r\n";
                    flush();
                }
                $sleep = max(0.2, $frameDelay - (microtime(true) - $started));
                $slept = 0.0;
                while ($slept < $sleep && !connection_aborted() && time() < $deadline) {
                    usleep(200000);
                    $slept += 0.2;
                }
            }
        });
    }
}
