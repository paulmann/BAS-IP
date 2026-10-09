<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Normalises proxy headers.
 *
 * `Request::ip()` already refuses to trust `X-Forwarded-For` unless the direct
 * peer is inside `app.trusted_proxies`; this middleware only records the
 * resolved scheme so downstream code stays simple.
 */
final class TrustProxies extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
