<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\RateLimiter;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Per-route throttling.
 *
 * A route opts in with `['throttle' => 'gate_open']` (a named bucket from
 * `security.rate_limits`) or `['throttle' => '30,60']` (max,window).
 * The bucket key combines the rule name, the user id (when authenticated) and
 * the IP so one abusive client cannot exhaust the limit for everybody.
 */
final class RateLimit extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $route  = $this->container->has('route') ? (array) $this->container->get('route') : [];
        $rule   = (string) ($route['options']['throttle'] ?? '');
        $config = $this->config();

        if ($rule === '') {
            // Global API throttle for anything under /api.
            if (!str_starts_with($request->path, '/api')) {
                return $next($request);
            }
            $rule  = 'api';
        }

        [$max, $window] = $this->resolve($rule);
        if ($max <= 0) {
            return $next($request);
        }

        /** @var RateLimiter $limiter */
        $limiter = $this->container->get(RateLimiter::class);
        $identity = $this->identity($request);
        $bucket   = sprintf('rl:%s:%s:%s', $rule, $request->path, $identity);

        $result = $limiter->attempt($bucket, $max, $window);

        $response = $next($request);
        $response->header('X-RateLimit-Limit', (string) $result['limit'], false);
        $response->header('X-RateLimit-Remaining', (string) $result['remaining'], false);
        $response->header('X-RateLimit-Reset', (string) $result['reset'], false);

        if ($result['allowed']) {
            return $response;
        }

        $this->logger('audit')->warning('Rate limit exceeded', [
            'rule' => $rule, 'path' => $request->path, 'ip' => $request->ip($config),
            'action' => 'rate_limited', 'is_security' => true,
        ]);

        $retryAfter = (string) max(1, $result['retryAfter']);
        if ($request->expectsJson()) {
            return Response::json([
                'ok' => false,
                'error' => 'Слишком много запросов. Повторите через ' . $retryAfter . ' с.',
                'retry_after' => (int) $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        return Response::html(
            '<!doctype html><html lang="ru"><meta charset="utf-8"><title>429</title>'
            . '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;background:#09090b;color:#fafafa">'
            . '<div style="text-align:center"><h1 style="font-size:3rem;margin:0">429</h1>'
            . '<p style="color:#a1a1aa">Слишком много запросов.<br>Повторите через ' . htmlspecialchars($retryAfter) . ' с.</p>'
            . '<a href="/" style="color:#60a5fa">На главную</a></div></body></html>',
            429
        )->header('Retry-After', $retryAfter);
    }

    /** @return array{0: int, 1: int} */
    private function resolve(string $rule): array
    {
        if (str_contains($rule, ',')) {
            [$max, $window] = array_pad(explode(',', $rule, 2), 2, '60');

            return [(int) $max, (int) $window];
        }
        $config = $this->config();

        return [
            $config->int('security.rate_limits.' . $rule . '.max', 0),
            $config->int('security.rate_limits.' . $rule . '.window', 60),
        ];
    }

    private function identity(Request $request): string
    {
        $session = $this->session();
        $userId  = $session?->get('user_id');
        if (is_int($userId) && $userId > 0) {
            return 'u' . $userId;
        }
        $token = $request->bearerToken();
        if ($token !== null) {
            return 't' . substr(hash('sha256', $token), 0, 12);
        }

        return 'ip' . $request->ip($this->config());
    }
}
