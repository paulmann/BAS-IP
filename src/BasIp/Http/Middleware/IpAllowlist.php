<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Support\Ip;
use Closure;

/**
 * Optional network-level allow-list for the admin area and the API.
 *
 * Kept from v1.x (`allowedIps`) but extended with CIDR ranges, wildcards and a
 * `log-only` mode so you can verify a rule before enforcing it.
 */
final class IpAllowlist extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->config();
        if (!$config->bool('auth.ip_allowlist.enabled', false)) {
            return $next($request);
        }

        $scope = $this->scopeFor($request->path);
        $applyTo = $config->list('auth.ip_allowlist.apply_to');
        if ($applyTo !== [] && !in_array('all', $applyTo, true) && !in_array($scope, $applyTo, true)) {
            return $next($request);
        }

        $ip = $request->ip($config);
        $allowed = Ip::allowed($ip, $config->list('auth.ip_allowlist.ips'), $config->list('auth.ip_allowlist.cidrs'));

        if (!$allowed) {
            $this->logger('audit')->warning('IP not in allow-list', [
                'ip' => $ip, 'path' => $request->path, 'scope' => $scope,
                'action' => 'ip_denied', 'is_security' => true,
            ]);

            if ($config->string('auth.ip_allowlist.deny_action', 'block') === 'log-only') {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return Response::json(['ok' => false, 'error' => 'Access denied'], 403);
            }

            return Response::html($this->forbiddenPage($ip), 403);
        }

        return $next($request);
    }

    private function scopeFor(string $path): string
    {
        return match (true) {
            str_starts_with($path, '/admin')  => 'admin',
            str_starts_with($path, '/api')    => 'api',
            str_starts_with($path, '/panel'),
            str_starts_with($path, '/cabinet') => 'panel',
            default                           => 'public',
        };
    }

    private function forbiddenPage(string $ip): string
    {
        $safe = htmlspecialchars(Ip::anonymize($ip), ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>403 · BAS-IP</title></head>'
            . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#09090b;color:#fafafa;'
            . 'font-family:ui-sans-serif,system-ui,sans-serif"><div style="text-align:center;max-width:28rem;padding:1.5rem">'
            . '<div style="font-size:3.5rem;font-weight:700;letter-spacing:-.03em">403</div>'
            . '<p style="color:#a1a1aa">Доступ с этого адреса запрещён администратором.</p>'
            . '<p style="color:#52525b;font-size:.8rem">Ваш IP: <code>' . $safe . '</code></p>'
            . '</div></body></html>';
    }
}
