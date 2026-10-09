<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Csrf;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Rejects state-changing requests without a valid CSRF token.
 *
 * Exceptions:
 *   * safe methods (GET/HEAD/OPTIONS)
 *   * routes listed in `security.csrf.except`
 *   * API calls authenticated with a bearer token / API key (no ambient cookie
 *     authority, so CSRF does not apply)
 *   * the signed guest link (single-use HMAC signature instead)
 */
final class VerifyCsrf extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $config = $this->config();
        if (!$config->bool('security.csrf.enabled', true)) {
            return $next($request);
        }

        $route = $this->container->has('route') ? (array) $this->container->get('route') : [];
        $name  = (string) ($route['name'] ?? '');
        $exempt = (array) $config->get('security.csrf.except', []);

        // `csrf_except => true` exempts the route outright (signed guest links,
        // device webhooks); `csrf_except => 'name*'` adds wildcard patterns.
        $exceptOption = $route['options']['csrf_except'] ?? false;
        if ($exceptOption === true) {
            return $next($request);
        }
        $exempt = array_merge($exempt, array_map('strval', (array) $exceptOption));

        if ($name !== '' && (in_array($name, $exempt, true) || $this->matchesWildcard($name, $exempt))) {
            return $next($request);
        }
        foreach ((array) ($route['options']['middleware'] ?? []) as $mw) {
            if ($mw === 'csrf:none') {
                return $next($request);
            }
        }

        // Token-authenticated machine calls.
        if ($request->bearerToken() !== null && !str_starts_with($request->path, '/admin')) {
            return $next($request);
        }

        /** @var Csrf $csrf */
        $csrf = $this->container->get(Csrf::class);
        if ($csrf->validate($request)) {
            return $next($request);
        }

        $this->logger('audit')->warning('CSRF validation failed', [
            'path' => $request->path, 'method' => $request->method,
            'ip' => $request->ip($config), 'action' => 'csrf_failed', 'is_security' => true,
        ]);

        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'error' => 'CSRF-токен недействителен или истёк. Обновите страницу и повторите действие.'], 419);
        }

        $session = $this->session();
        $session?->flash('error', 'Сессия устарела. Форма не была отправлена — повторите действие.');

        return Response::redirect($request->url($request->referer() !== '' ? (string) parse_url($request->referer(), PHP_URL_PATH) : '/'));
    }

    /** @param array<int, mixed> $patterns */
    private function matchesWildcard(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $name) {
                return true;
            }
            if (str_ends_with($pattern, '*') && str_starts_with($name, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }
}
