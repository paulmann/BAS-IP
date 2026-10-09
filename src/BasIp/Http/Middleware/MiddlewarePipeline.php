<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Container;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;
use Throwable;

/**
 * Onion-style middleware pipeline.
 */
final class MiddlewarePipeline
{
    /** @var array<int, Middleware> */
    private array $stack = [];

    /** @param array<int, class-string<Middleware>> $middleware */
    public function __construct(private readonly Container $container, array $middleware)
    {
        foreach ($middleware as $class) {
            $instance = $container->has($class) ? $container->get($class) : new $class($container);
            if ($instance instanceof Middleware) {
                $this->stack[] = $instance;
            }
        }
    }

    /** @param Closure(Request): Response $destination */
    public function send(Request $request, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->stack),
            static fn (Closure $next, Middleware $middleware): Closure => static fn (Request $request): Response => $middleware->handle($request, $next),
            $destination
        );

        $response = $pipeline($request);

        foreach ($this->stack as $middleware) {
            try {
                $middleware->terminate($request, $response);
            } catch (Throwable) {
                // Termination must never break the response.
            }
        }

        return $response;
    }
}
