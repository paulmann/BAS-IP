<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Core\Session;

/**
 * Convenience base class: container access, config, logger and session
 * shortcuts, plus a no-op `terminate()`.
 */
abstract class AbstractMiddleware implements Middleware
{
    public function __construct(protected readonly Container $container)
    {
    }

    public function terminate(Request $request, Response $response): void
    {
    }

    protected function config(): Config
    {
        return $this->container->get(Config::class);
    }

    protected function logger(string $channel = 'app'): Logger
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);

        return $logger->channel($channel);
    }

    protected function session(): ?Session
    {
        return $this->container->has(Session::class) ? $this->container->get(Session::class) : null;
    }
}
