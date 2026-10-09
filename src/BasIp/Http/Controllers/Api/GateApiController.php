<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Api;

use BasIp\Auth\ApiPrincipal;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\GateEvent;
use BasIp\Domain\Model\Resident;
use BasIp\Http\Controller;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Support\Plate;

/**
 * Machine API for gate operations.
 *
 * Authenticated with a bearer token (`Authorization: Bearer basip_…`) and
 * authorised by *scopes*, not roles — a leaked automation token can never
 * become an admin session. Everything is written to the gate log with
 * `access_type = api` and `source = api`.
 */
final class GateApiController extends Controller
{
    private function principal(): ApiPrincipal
    {
        $principal = $this->c->get('api.principal');
        if (!$principal instanceof ApiPrincipal) {
            $this->forbidden('Требуется API-токен.');
        }

        return $principal;
    }

    private function gate(): GateService
    {
        return $this->svc(GateService::class);
    }

    public function status(Request $request): Response
    {
        $this->principal();
        $result = $this->gate()->status($request->str('device') ?: null, !$request->bool('fresh'));
        $time = $request->bool('with_time') ? $this->gate()->time($request->str('device') ?: null) : null;

        return $this->json([
            'ok'      => $result->ok,
            'message' => $result->message,
            'status'  => $result->data,
            'device'  => $this->svc(\BasIp\Device\DeviceManager::class)->resolve($request->str('device') ?: null)?->label(),
            'time'    => $time?->data,
            'duration_ms' => round($result->durationMs, 1),
            'server_time' => date('c'),
        ], $result->ok ? 200 : 502);
    }

    public function open(Request $request): Response
    {
        $principal = $this->principal();

        $resident = $this->resolveResident($request);
        $result = $this->gate()->open([
            'resident'    => $resident,
            'api'         => $principal,
            'access_type' => $request->str('access_type') ?: GateEvent::ACCESS_API,
            'source'      => 'api',
            'device'      => $request->str('device') ?: null,
            'channel'     => $request->int('channel') ?: null,
            'plate'       => $request->str('plate'),
            'note'        => $request->str('note') ?: ('API: ' . $principal->name),
        ], $request);

        return $this->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'error_code' => $result->errorCode,
            'duration_ms' => round($result->durationMs, 1),
            'resident' => $resident?->displayName(),
            'opened_at' => date('c'),
        ], $result->ok ? 200 : 422);
    }

    public function close(Request $request): Response
    {
        $principal = $this->principal();
        $result = $this->gate()->close([
            'api' => $principal, 'source' => 'api', 'device' => $request->str('device') ?: null,
        ], $request);

        return $this->json(['ok' => $result->ok, 'message' => $result->message], $result->ok ? 200 : 422);
    }

    public function events(Request $request): Response
    {
        $this->principal();
        $filters = $this->filters($request, ['from', 'to', 'access_type', 'result', 'resident_id', 'plate', 'device_id']);
        $paginator = $this->gate()->events($filters, min(500, $this->perPage($request, 100)), $this->page($request));

        return $this->paginateJson($paginator, static fn (GateEvent $e): array => [
            'id' => $e->id,
            'occurred_at' => $e->occurredAt,
            'actor_type' => $e->actorType,
            'actor' => $e->actorLabel(),
            'resident_id' => $e->residentId,
            'apartment' => $e->apartment,
            'access_type' => $e->accessType,
            'access_label' => $e->accessLabel(),
            'action' => $e->action,
            'result' => $e->result,
            'reason' => $e->reason,
            'plate' => $e->plate,
            'source' => $e->source,
            'device' => $e->deviceName,
            'duration_ms' => $e->durationMs,
        ]);
    }

    public function snapshot(Request $request): Response
    {
        $this->principal();
        $jpeg = $this->gate()->snapshot($request->str('device') ?: null);
        if ($jpeg === null || $jpeg === '') {
            return Response::noContent(404);
        }

        return new Response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=1']);
    }

    /* ─────────────────────────── guest passes ─────────────────────────── */

    public function createGuestPass(Request $request): Response
    {
        $principal = $this->principal();
        $resident = $this->resolveResident($request, true);
        if ($resident === null) {
            return $this->jsonError('Укажите resident_id, apartment или plate.', 422);
        }

        /** @var GuestPassService $passes */
        $passes = $this->svc(GuestPassService::class);
        $result = $passes->create([
            'resident_id' => $resident->id,
            'ttl_hours'   => $request->int('ttl_hours') ?: $this->settingsService()->int('guest.default_ttl_hours', 12),
            'guest_name'  => $request->str('guest_name'),
            'guest_phone' => $request->str('guest_phone'),
            'car_plate'   => $request->str('car_plate'),
            'purpose'     => $request->str('purpose') ?: ('API: ' . $principal->name),
            'max_uses'    => $request->int('max_uses'),
            'notify'      => $request->bool('notify', false),
            'notify_guest' => $request->bool('notify_guest', false),
            'link_base'   => rtrim($this->config()->string('app.url', ''), '/'),
        ], $principal->user, $request);

        if (!$result['ok']) {
            return $this->jsonError((string) ($result['error'] ?? 'Ошибка'), 422);
        }

        return $this->json([
            'ok' => true,
            'id' => $result['id'],
            'code' => $result['code'],
            'pin' => $result['pin'],
            'link' => $result['link'],
            'token' => $result['token'],
            'valid_from' => $result['pass']?->validFrom,
            'valid_until' => $result['pass']?->validUntil,
            'ttl_hours' => $result['pass']?->ttlHours,
        ], 201);
    }

    public function listGuestPasses(Request $request): Response
    {
        $this->principal();
        /** @var GuestPassService $passes */
        $passes = $this->svc(GuestPassService::class);
        $paginator = $passes->list($this->filters($request, ['status', 'resident_id', 'from', 'to', 'q']), $this->perPage($request, 50), $this->page($request));

        return $this->paginateJson($paginator, static fn (\BasIp\Domain\Model\GuestPass $p): array => [
            'id' => $p->id, 'code' => $p->code, 'status' => $p->status,
            'resident_id' => $p->residentId, 'resident' => $p->residentName, 'apartment' => $p->apartment,
            'guest_name' => $p->guestName, 'guest_phone' => $p->guestPhone, 'car_plate' => $p->carPlate,
            'valid_from' => $p->validFrom, 'valid_until' => $p->validUntil, 'ttl_hours' => $p->ttlHours,
            'used_count' => $p->usedCount, 'max_uses' => $p->maxUses, 'purpose' => $p->purpose,
        ]);
    }

    public function revokeGuestPass(Request $request, string $id = ''): Response
    {
        $principal = $this->principal();
        /** @var GuestPassService $passes */
        $passes = $this->svc(GuestPassService::class);
        $ok = $passes->revoke((int) $id, $request->str('reason') ?: ('Отозван через API: ' . $principal->name), $principal->user, $request);

        return $this->json(['ok' => $ok], $ok ? 200 : 404);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function resolveResident(Request $request, bool $allowPlate = false): ?Resident
    {
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);

        if ($request->filled('resident_id')) {
            return $this->residentRepo()->findById($request->int('resident_id'));
        }
        if ($request->filled('apartment')) {
            $row = $this->db()->table('residents')
                ->where('apartment', '=', $request->str('apartment'))
                ->where('is_active', '=', 1)
                ->whereNull('deleted_at')
                ->first();

            return $row === null ? null : $this->residentRepo()->hydrate($row, true);
        }
        if ($allowPlate && $request->filled('plate')) {
            return $gate->findByPlate($request->str('plate'));
        }

        return null;
    }
}
