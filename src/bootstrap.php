<?php

/**
 * Application bootstrap — builds the service container.
 *
 * Every entry point (public/index.php, bin/bas-ip, tests) funnels through
 * here so there is exactly one place where services are wired.
 *
 * @return BasIp\Core\App
 */

declare(strict_types=1);

use BasIp\Core\App;
use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Crypto;
use BasIp\Core\Csrf;
use BasIp\Core\HttpClient;
use BasIp\Core\Lang;
use BasIp\Core\Logger;
use BasIp\Core\RateLimiter;
use BasIp\Core\Request;
use BasIp\Core\Router;
use BasIp\Core\Session;
use BasIp\Core\View;
use BasIp\Db\Database;
use BasIp\Db\Migrator;
use BasIp\Db\Schema;

require_once __DIR__ . '/autoload.php';

return App::boot(static function (Container $c, string $root): void {
    /* ── foundation ─────────────────────────────────────────────────── */

    $c->bind(Config::class, static fn (): Config => Config::boot($root));

    $c->bind(Clock::class, static function (Container $c): Clock {
        /** @var Config $config */
        $config = $c->get(Config::class);
        $timezone = $config->string('app.timezone', 'UTC');
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }
        date_default_timezone_set($tz->getName());
        $clock = new Clock($tz);
        Clock::setInstance($clock);

        return $clock;
    });

    $c->bind(Logger::class, static fn (Container $c): Logger => new Logger(
        $c->get(Config::class),
        $c->get(Clock::class),
    ));

    $c->bind(Cache::class, static fn (Container $c): Cache => new Cache($c->get(Config::class)));

    $c->bind(Crypto::class, static fn (Container $c): Crypto => new Crypto($c->get(Config::class)->appKey()));

    $c->bind(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter(
        $c->get(Config::class),
        $c->get(Clock::class),
    ));

    /* ── database ───────────────────────────────────────────────────── */

    $c->bind(Database::class, static fn (Container $c): Database => new Database(
        $c->get(Config::class),
        $c->get(Clock::class),
        $c->get(Logger::class),
    ));

    $c->bind(Schema::class, static fn (Container $c): Schema => new Schema(
        $c->get(Database::class),
        $c->get(Logger::class),
    ));

    $c->bind(Migrator::class, static fn (Container $c): Migrator => new Migrator(
        $c->get(Database::class),
        $c->get(Schema::class),
        $c->get(Config::class),
        $c->get(Clock::class),
        $c->get(Logger::class),
    ));

    /* ── http ───────────────────────────────────────────────────────── */

    // In a web request App::handle() replaces this with the captured request
    // before dispatching. For CLI/tests a synthetic request keeps services
    // (Session, Csrf, View) usable without a live $_SERVER.
    $c->bind(Request::class, static function (Container $c): Request {
        /** @var Config $config */
        $config = $c->get(Config::class);

        return PHP_SAPI === 'cli'
            ? Request::make('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/bin/bas-ip'])
            : Request::capture($config);
    });

    $c->bind(Router::class, static function (Container $c): Router {
        /** @var Config $config */
        $config = $c->get(Config::class);
        $router = new Router();
        $routes = require $config->getPath('config') . '/routes.php';
        if (is_callable($routes)) {
            $routes($router, $c);
        } elseif (is_array($routes)) {
            /** @var callable(Router, Container): void $register */
            $register = $routes['register'] ?? static function (): void {};
            $register($router, $c);
        }

        return $router;
    });

    $c->bind(Lang::class, static fn (Container $c): Lang => new Lang(
        $c->get(Config::class),
        (string) $c->get(Config::class)->string('ui.language', 'ru'),
    ));

    $c->bind(Csrf::class, static fn (Container $c): Csrf => new Csrf(
        $c->get(Session::class),
        $c->get(Config::class),
    ));

    $c->bind(View::class, static fn (Container $c): View => new View(
        $c->get(Config::class),
        $c->get(Lang::class),
        $c->get(Csrf::class),
        $c->get(Router::class),
    ));

    $c->factory(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient(
        $c->get(Config::class),
        $c->get(Logger::class),
    ));

    /* ── domain services ────────────────────────────────────────────── */

    BasIp\Services::register($c);
});
