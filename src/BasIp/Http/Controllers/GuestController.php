<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\GateEvent;
use BasIp\Http\Controller;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Support\Money;

/**
 * Public guest page (`/guest/<token>`).
 *
 * No login required — the one-time token *is* the credential. Hardened by:
 *   • storing only a SHA-256 hash of the token,
 *   • short validity (12 h by default) and an optional use counter,
 *   • per-IP rate limiting on both viewing and opening,
 *   • the resident's own flags re-checked at open time (a disabled resident
 *     instantly loses every guest pass they issued),
 *   • every attempt (allowed *and* denied) written to the gate log.
 */
final class GuestController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('guest/index', [
            'title' => 'Гостевой доступ',
            'layout' => 'guest',
        ]);
    }

    public function show(Request $request, string $token = ''): Response
    {
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        $pass = $guests->findByToken($token);

        if ($pass === null) {
            return $this->view('guest/invalid', [
                'title' => 'Ссылка недействительна', 'layout' => 'guest',
                'reason' => 'Гостевая ссылка не найдена или уже не действует.',
            ], 404);
        }

        $resident = $pass->residentId !== null ? $this->residentRepo()->findById($pass->residentId) : null;
        $now = time();

        return $this->view('guest/pass', [
            'title'     => 'Гостевой доступ',
            'layout'    => 'guest',
            'pass'      => $pass,
            'resident'  => $resident,
            'valid'     => $pass->isActive($now),
            'reason'    => $this->denyReason($pass, $resident),
            'opensLeft' => $pass->maxUses > 0 ? max(0, $pass->maxUses - $pass->usedCount) : null,
            'organisation' => $this->settingsService()->string('ui.organisation_name', $this->config()->string('app.name', 'BAS-IP')),
            'supportPhone' => $this->settingsService()->string('ui.support_phone', ''),
            'token'     => $token,
        ]);
    }

    public function open(Request $request, string $token = ''): Response
    {
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);

        $pass = $guests->findByToken($token);
        if ($pass === null) {
            return $this->jsonError('Гостевая ссылка недействительна.', 404);
        }

        $resident = $pass->residentId !== null ? $this->residentRepo()->findById($pass->residentId) : null;

        $result = $gate->open([
            'guest_pass'  => $pass,
            'resident'    => $resident,
            'access_type' => GateEvent::ACCESS_GUEST,
            'source'      => 'web',
            'device'      => $request->str('device') ?: null,
            'plate'       => $pass->carPlate,
            'note'        => 'Гостевой доступ ' . $pass->code . ($pass->guestName !== null ? ' · ' . $pass->guestName : ''),
        ], $request);

        if (!$result->ok) {
            return $this->json([
                'ok' => false, 'message' => $result->message, 'error_code' => $result->errorCode,
                'retry_after' => $result->errorCode === 'cooldown' ? 5 : null,
            ], 422);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Шлагбаум открыт. Проезжайте!',
            'used_count' => $pass->usedCount + 1,
            'opens_left' => $pass->maxUses > 0 ? max(0, $pass->maxUses - $pass->usedCount - 1) : null,
            'valid_until' => $pass->validUntil,
            'duration_ms' => round($result->durationMs),
        ]);
    }

    /** Opening by a human-readable code (for intercoms and manual entry). */
    public function byCode(Request $request): Response
    {
        $code = trim($request->str('code'));
        if ($code === '') {
            return $this->jsonError('Введите код гостевого доступа.', 400);
        }

        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        $pass = $guests->findByCode($code);
        if ($pass === null) {
            return $this->jsonError('Код не найден.', 404);
        }

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $resident = $pass->residentId !== null ? $this->residentRepo()->findById($pass->residentId) : null;

        $result = $gate->open([
            'guest_pass'  => $pass,
            'resident'    => $resident,
            'access_type' => GateEvent::ACCESS_GUEST,
            'source'      => 'web',
            'plate'       => $pass->carPlate,
            'note'        => 'Открытие по коду ' . $pass->code,
        ], $request);

        if (!$result->ok) {
            return $this->json(['ok' => false, 'message' => $result->message, 'error_code' => $result->errorCode], 422);
        }

        return $this->json([
            'ok' => true, 'message' => 'Шлагбаум открыт.', 'code' => $pass->code,
            'valid_until' => $pass->validUntil, 'guest' => $pass->guestName,
        ]);
    }

    private function denyReason($pass, $resident): ?string
    {
        $now = time();
        if ($pass->isRevoked()) {
            return 'Доступ отозван' . ($pass->revokeReason !== null ? ': ' . $pass->revokeReason : '.');
        }
        if ($pass->isExpired($now)) {
            return 'Срок действия истёк ' . ($pass->validUntil !== null ? date('d.m.Y H:i', (int) strtotime($pass->validUntil)) : '') . '.';
        }
        if ($pass->validFrom !== null && strtotime($pass->validFrom) > $now) {
            return 'Доступ начнёт действовать с ' . date('d.m.Y H:i', (int) strtotime($pass->validFrom)) . '.';
        }
        if ($pass->maxUses > 0 && $pass->usedCount >= $pass->maxUses) {
            return 'Исчерпан лимит использований (' . $pass->maxUses . ').';
        }
        if ($resident !== null && !$resident->isActive) {
            return 'Доступ владельца приостановлен. Обратитесь к администратору.';
        }
        if ($resident !== null && !$resident->accessGranted) {
            return 'Владельцу приостановлен доступ к шлагбауму.';
        }

        return null;
    }
}
