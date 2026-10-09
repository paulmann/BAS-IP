<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Maintenance / read-only mode.
 *
 * Enabled by `app.maintenance`, the `storage/framework/maintenance.flag` file
 * (created by the updater and `bin/bas-ip down`) or the `maintenance_mode`
 * setting. Admins and the gate-open endpoint always stay reachable — nobody
 * should be locked out of their own yard.
 */
final class MaintenanceMode extends AbstractMiddleware
{
    private const ALWAYS_ALLOWED = ['/login', '/health', '/admin/system', '/api/gate/open', '/assets'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isDown()) {
            return $next($request);
        }

        foreach (self::ALWAYS_ALLOWED as $prefix) {
            if (str_starts_with($request->path, $prefix)) {
                return $next($request);
            }
        }

        $guard = $this->container->has('auth.guard') ? $this->container->get('auth.guard') : null;
        if ($guard !== null && method_exists($guard, 'isAdmin') && $guard->isAdmin()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return Response::json([
                'ok' => false,
                'error' => 'Сервис находится в режиме обслуживания',
                'maintenance' => true,
            ], 503)->withHeaders(['Retry-After' => '300']);
        }

        $message = (string) $this->message();

        return Response::html($this->page($message), 503)->withHeaders(['Retry-After' => '300']);
    }

    public function isDown(): bool
    {
        if ($this->config()->bool('app.maintenance', false)) {
            return true;
        }

        return is_file($this->flagFile());
    }

    public function message(): string
    {
        $file = $this->flagFile();
        if (is_file($file)) {
            $raw = (string) @file_get_contents($file);
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['message'])) {
                return (string) $data['message'];
            }
            if (trim($raw) !== '' && !str_starts_with(trim($raw), '{')) {
                return trim($raw);
            }
        }

        return (string) $this->config()->get('app.maintenance_message', 'Ведутся технические работы. Шлагбаум работает в штатном режиме.');
    }

    public function flagFile(): string
    {
        return $this->config()->getPath('storage') . '/framework/maintenance.flag';
    }

    private function page(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!doctype html>
        <html lang="ru" class="dark">
        <head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Технические работы · BAS-IP</title>
        <style>
          :root{color-scheme:dark}
          body{margin:0;min-height:100vh;display:grid;place-items:center;background:#09090b;color:#fafafa;
               font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:1.5rem}
          .card{max-width:34rem;width:100%;border:1px solid #27272a;border-radius:1rem;background:#111113;
                padding:2.5rem;text-align:center;box-shadow:0 20px 60px -20px rgba(0,0,0,.9)}
          h1{font-size:1.5rem;margin:0 0 .75rem}
          p{color:#a1a1aa;line-height:1.7;margin:0 0 1.5rem}
          .dot{width:.75rem;height:.75rem;border-radius:9999px;background:#f59e0b;display:inline-block;
               margin-right:.5rem;animation:pulse 1.6s ease-in-out infinite}
          @keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
          code{background:#1c1c1f;padding:.15rem .45rem;border-radius:.35rem;font-size:.85em}
        </style>
        </head>
        <body><div class="card">
          <div style="font-size:2.5rem;margin-bottom:1rem"><span class="dot"></span></div>
          <h1>Технические работы</h1>
          <p>{$safe}</p>
          <p style="font-size:.8rem;color:#71717a">Открыть шлагбаум можно как обычно — эта страница касается только веб-интерфейса.<br>
          Обновите страницу через несколько минут.</p>
        </div></body></html>
        HTML;
    }
}
