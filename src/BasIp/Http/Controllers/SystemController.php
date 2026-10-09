<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Core\App;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\GateEvent;
use BasIp\Http\Controller;
use BasIp\Services\DataService;
use BasIp\Services\GateService;
use BasIp\Support\Ip;

/**
 * Public, unauthenticated endpoints: health probes, Prometheus metrics,
 * the PWA manifest, the version stamp and the v1.x compatibility shims.
 */
final class SystemController extends Controller
{
    /** Liveness/readiness for load balancers, Kubernetes and UptimeRobot. */
    public function health(Request $request): Response
    {
        $deep = $request->bool('deep');
        /** @var DataService $data */
        $data = $this->svc(DataService::class);
        $health = $data->health($deep);

        if ($request->expectsJson()) {
            return $this->json($health, $health['status'] === 'ok' ? 200 : ($health['status'] === 'degraded' ? 200 : 503));
        }

        $status = strtoupper((string) $health['status']);
        $body = sprintf("BAS-IP Gate Manager %s — %s\n", App::VERSION, $status);
        foreach ($health['checks'] as $name => $check) {
            $body .= sprintf("  [%s] %-22s %s%s\n",
                ($check['ok'] ?? false) ? ' OK ' : 'FAIL',
                (string) $name,
                (string) ($check['value'] ?? ''),
                isset($check['message']) && $check['message'] !== null ? '  — ' . $check['message'] : ''
            );
        }

        return Response::text($body, $health['status'] === 'ok' ? 200 : 503)->noCache();
    }

    public function healthJson(Request $request): Response
    {
        /** @var DataService $data */
        $data = $this->svc(DataService::class);
        $health = $data->health($request->bool('deep'));

        return $this->json($health, $health['status'] === 'ok' ? 200 : 503)->noCache();
    }

    /** Prometheus text exposition format. */
    public function metrics(Request $request): Response
    {
        $token = $this->config()->string('integrations.prometheus.token', '');
        if ($token !== '' && !hash_equals($token, (string) $request->bearerToken())) {
            return Response::text("forbidden\n", 403);
        }

        /** @var DataService $data */
        $data = $this->svc(DataService::class);
        $info = $data->systemInfo();

        $lines = [];
        $lines[] = '# HELP basip_up Whether the application considers itself healthy.';
        $lines[] = '# TYPE basip_up gauge';
        $lines[] = 'basip_up ' . ($data->health()['status'] === 'ok' ? 1 : 0);

        $lines[] = '# HELP basip_info Build information.';
        $lines[] = '# TYPE basip_info gauge';
        $lines[] = sprintf('basip_info{version="%s",php="%s",driver="%s"} 1', App::VERSION, PHP_VERSION, $info['database']['driver'] ?? '?');

        $emit = function (string $name, string $help, string $type, array $samples) use (&$lines): void {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' ' . $type;
            foreach ($samples as $labels => $value) {
                $lines[] = $name . ($labels !== '' ? '{' . $labels . '}' : '') . ' ' . $value;
            }
        };

        $counters = (array) ($info['counters'] ?? []);
        $emit('basip_entities', 'Row counts of the main tables.', 'gauge', array_map(
            static fn ($v): string => (string) $v,
            array_combine(array_map(static fn (string $k): string => 'entity="' . $k . '"', array_keys($counters)), $counters)
        ));

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $stats = $gate->stats(30);
        $emit('basip_gate_events', 'Gate events over the last 30 days.', 'counter', [
            'result="success"' => (string) $stats['success'],
            'result="denied"'  => (string) $stats['denied'],
            'result="error"'   => (string) $stats['errors'],
            'access="own"'     => (string) $stats['own'],
            'access="guest"'   => (string) $stats['guest'],
            'access="manual"'  => (string) $stats['manual'],
            'period="today"'   => (string) $stats['today'],
        ]);

        $queue = $this->db()->table('outbox')->select('status', 'COUNT(*) AS cnt')->groupBy('status')->get();
        $samples = [];
        foreach ($queue as $row) {
            $samples['status="' . $row['status'] . '"'] = (string) $row['cnt'];
        }
        $emit('basip_outbox', 'Notification queue depth by status.', 'gauge', $samples);

        $lines[] = '# HELP basip_database_size_bytes Approximate database size.';
        $lines[] = '# TYPE basip_database_size_bytes gauge';
        $lines[] = 'basip_database_size_bytes ' . (int) $this->db()->sizeBytes();

        return Response::text(implode("\n", $lines) . "\n")->noCache()
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /** PWA manifest so the panel can be installed on a phone home screen. */
    public function manifest(Request $request): Response
    {
        $name = $this->settingsService()->string('ui.organisation_name', '') ?: $this->config()->string('app.name', 'BAS-IP');

        return $this->json([
            'name' => $name . ' — управление шлагбаумом',
            'short_name' => $this->config()->string('app.short_name', 'BAS-IP'),
            'description' => 'Панель управления шлагбаумом BAS-IP: доступ, гости, оплаты.',
            'start_url' => $request->url('/panel'),
            'scope' => $request->url('/') . '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#09090b',
            'theme_color' => '#18181b',
            'lang' => $this->config()->string('ui.language', 'ru'),
            'icons' => [
                ['src' => $request->url('/assets/favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => $request->url('/assets/img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $request->url('/assets/img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            'shortcuts' => [
                ['name' => 'Открыть шлагбаум', 'url' => $request->url('/panel'), 'icons' => []],
                ['name' => 'Гостевой доступ', 'url' => $request->url('/cabinet/guest'), 'icons' => []],
                ['name' => 'Мои оплаты', 'url' => $request->url('/cabinet/payments'), 'icons' => []],
            ],
        ])->header('Content-Type', 'application/manifest+json; charset=UTF-8');
    }

    public function version(Request $request): Response
    {
        return $this->json([
            'name' => $this->config()->string('app.name'),
            'version' => App::VERSION,
            'codename' => App::CODENAME,
            'php' => PHP_VERSION,
            'build' => $this->settingsService()->string('system.installed_version', App::VERSION),
            'installed_at' => $this->settingsService()->get('system.installed_at'),
        ])->cache(300, true);
    }

    /* ─────────────────────────── v1.x compatibility ─────────────────────────── */

    /** `bas-ip.php` → the new authenticated panel. */
    public function legacyPanel(Request $request): Response
    {
        return $this->redirect('/panel', 301);
    }

    /**
     * `bas_ip.php?cmd=…` → the new API.
     *
     * Kept so existing automations keep working; it now requires either a
     * session, an API token or an entry in `auth.ip_allowlist`, and every call
     * is logged (v1 only logged errors).
     */
    public function legacyApi(Request $request): Response
    {
        $commandMap = [
            'status'      => fn (): Response => $this->forward('GET', '/api/v1/gate/status', $request),
            'get_time'    => fn (): Response => $this->forward('GET', '/api/v1/gate/status?with_time=1', $request),
            'get_cameras' => fn (): Response => $this->forward('GET', '/api/v1/gate/snapshot', $request),
            'lock_open'   => fn (): Response => $this->forward('POST', '/api/v1/gate/open', $request),
            'reboot'      => fn (): Response => $this->forward('POST', '/api/v1/gate/reboot', $request),
            'sip_enable'  => fn (): Response => $this->forward('POST', '/api/v1/gate/sip?enable=1', $request),
            'sip_disable' => fn (): Response => $this->forward('POST', '/api/v1/gate/sip?enable=0', $request),
        ];

        $cmd = strtolower(trim($request->str('cmd')));
        if (!isset($commandMap[$cmd])) {
            return $this->jsonError('Неизвестная команда. Доступно: ' . implode(', ', array_keys($commandMap)), 400);
        }

        $this->auditService()->record('api.legacy', $this->user(), [
            'subject_type' => 'device', 'subject_id' => $cmd, 'actor_name' => $this->user()?->login ?? 'anonymous',
            'ip' => $request->ip($this->config()),
            'meta' => ['deprecated' => true, 'replacement' => '/api/v1'],
        ], $request);

        return $commandMap[$cmd]();
    }

    private function forward(string $method, string $path, Request $request): Response
    {
        $user = $this->user();
        if ($user === null && !$this->legacyIpAllowed($request)) {
            return $this->jsonError('Требуется авторизация (сессия или API-токен).', 401);
        }

        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $device = $request->str('device') ?: null;

        return match (true) {
            str_contains($path, 'gate/open') => $this->gateResultJson($gate->open([
                'user' => $user, 'access_type' => $user !== null && $user->isStaff() ? GateEvent::ACCESS_REMOTE : GateEvent::ACCESS_API,
                'source' => 'api', 'device' => $device,
            ], $request)),
            str_contains($path, 'gate/close') => $this->gateResultJson($gate->close(['user' => $user, 'source' => 'api', 'device' => $device], $request)),
            str_contains($path, 'reboot') => $this->gateResultJson($gate->reboot($device, $user, $request)),
            str_contains($path, 'sip') => $this->gateResultJson($gate->sip($request->bool('enable', true), $device, $user, $request)),
            str_contains($path, 'with_time') => $this->json($gate->time($device)->toArray()),
            str_contains($path, 'snapshot') => ($jpeg = $gate->snapshot($device)) !== null
                ? new Response($jpeg, 200, ['Content-Type' => 'image/jpeg'])
                : Response::noContent(404),
            default => $this->json($gate->status($device)->toArray()),
        };
    }

    private function gateResultJson(\BasIp\Device\GateResult $result): Response
    {
        return $this->json($result->toArray() + ['error' => $result->ok ? null : $result->message], $result->ok ? 200 : 502);
    }

    private function legacyIpAllowed(Request $request): bool
    {
        $ip = $request->ip($this->config());
        $allowed = array_merge(
            $this->config()->list('auth.ip_allowlist.ips'),
            $this->config()->list('auth.ip_allowlist.cidrs')
        );

        return $allowed !== [] && Ip::matches($ip, $allowed);
    }

    /** `get_last_image.php` → the proxied camera snapshot. */
    public function legacyImage(Request $request): Response
    {
        $user = $this->user();
        if ($user === null && !$this->legacyIpAllowed($request)) {
            return Response::text('', 401);
        }
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        $jpeg = $gate->snapshot($request->str('device') ?: null);

        if ($jpeg === null || $jpeg === '') {
            return Response::text('', 404);
        }

        return new Response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=1',
        ]);
    }
}
