<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Container;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Middleware contract.
 *
 * `handle()` either returns a Response (short-circuit) or calls `$next`.
 * `terminate()` runs after the response has been sent — useful for writing
 * access logs and session metadata without delaying the reply.
 */
interface Middleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response;

    public function terminate(Request $request, Response $response): void;
}
