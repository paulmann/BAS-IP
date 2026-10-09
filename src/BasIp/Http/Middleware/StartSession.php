<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Core\Session;
use BasIp\Core\View;
use Closure;

/**
 * Boots the hardened session for browser requests and shares the flash data,
 * old input and validation errors with the view layer.
 */
final class StartSession extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PHP_SAPI === 'cli' || !$this->container->has(Session::class)) {
            return $next($request);
        }

        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->start();
        $session->ageFlash();

        if ($this->container->has(View::class)) {
            /** @var View $view */
            $view = $this->container->get(View::class);
            $view->shareMany([
                'session' => $session,
                'flash'   => $session->allFlashed(),
                'old'     => $session->get('_old_input', []),
                'errors'  => $session->get('_errors', []),
                'sessionId' => $session->id(),
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (!$this->container->has(Session::class)) {
            return;
        }
        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->touch();
    }
}
