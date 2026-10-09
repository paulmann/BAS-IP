<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Auth\ApiPrincipal;
use BasIp\Auth\Guard;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Authenticates the request.
 *
 * Route options:
 *   'auth'  => true            require any signed-in principal
 *   'auth'  => 'api'           require a valid API token (sets `api.principal`)
 *   'auth'  => false / absent  public route
 */
final class Authenticate extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $route  = $this->container->has('route') ? (array) $this->container->get('route') : [];
        $mode   = $route['options']['auth'] ?? false;

        if ($mode === false || $mode === null || $mode === '') {
            // Still resolve the user when a session exists so views can show it.
            if ($this->container->has(Guard::class)) {
                $guard = $this->container->get(Guard::class);
                $user  = $guard->user();
                if ($user !== null) {
                    $this->container->set('auth.user', $user);
                    $this->container->set('auth.guard', $guard);
                }
            }

            return $next($request);
        }

        // ── API token mode ────────────────────────────────────────────────
        if ($mode === 'api') {
            $token = $request->bearerToken();
            if ($token === null || $token === '') {
                return $this->deny($request, 401, 'Требуется API-токен (заголовок Authorization: Bearer …).');
            }
            /** @var Guard $guard */
            $guard = $this->container->get(Guard::class);
            $principal = $guard->authenticateApiToken($token);
            if ($principal === null) {
                $this->logger('audit')->warning('Invalid API token', [
                    'ip' => $request->ip($this->config()), 'path' => $request->path,
                    'action' => 'api_token_invalid', 'is_security' => true,
                ]);

                return $this->deny($request, 401, 'API-токен недействителен или отозван.');
            }
            $this->container->set('api.principal', $principal);
            $this->container->set('auth.principal', $principal);
            if ($principal->user !== null) {
                $this->container->set('auth.user', $principal->user);
            }

            return $next($request);
        }

        // ── Session mode ──────────────────────────────────────────────────
        /** @var Guard $guard */
        $guard = $this->container->get(Guard::class);
        $user  = $guard->user();

        if ($user === null) {
            // A token-authenticated call may still reach a session-protected route.
            $token = $request->bearerToken();
            if ($token !== null && $token !== '') {
                $principal = $guard->authenticateApiToken($token);
                if ($principal instanceof ApiPrincipal) {
                    $this->container->set('api.principal', $principal);
                    $this->container->set('auth.principal', $principal);

                    return $next($request);
                }
            }

            if ($request->expectsJson()) {
                return $this->deny($request, 401, 'Требуется авторизация.');
            }
            $session = $this->session();
            $session?->set('intended_url', $request->fullUrl());
            $session?->flash('error', 'Войдите, чтобы продолжить.');

            return Response::redirect($request->url('/login'));
        }

        if (!$user->isActive) {
            return $this->deny($request, 403, 'Учётная запись отключена. Обратитесь к администратору.');
        }

        $this->container->set('auth.user', $user);
        $this->container->set('auth.guard', $guard);
        $this->container->set('auth.principal', $user);

        return $next($request);
    }

    private function deny(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'error' => $message, 'status' => $status], $status);
        }
        if ($status === 401) {
            $session = $this->session();
            $session?->set('intended_url', $request->fullUrl());

            return Response::redirect($request->url('/login'));
        }

        return Response::html(
            '<!doctype html><html lang="ru"><meta charset="utf-8"><title>' . $status . '</title>'
            . '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;'
            . 'background:#09090b;color:#fafafa;text-align:center;padding:1.5rem">'
            . '<div><div style="font-size:3rem;font-weight:700">' . $status . '</div>'
            . '<p style="color:#a1a1aa">' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            . '<a href="/" style="color:#60a5fa">На главную</a></div></body></html>',
            $status
        );
    }
}
