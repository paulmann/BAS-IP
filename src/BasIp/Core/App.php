<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Http\Middleware\MiddlewarePipeline;
use BasIp\Http\RoutingException;
use Closure;
use ErrorException;
use Throwable;

/**
 * Application kernel.
 *
 * Owns the container, the request lifecycle, the error handler and the
 * middleware pipeline. Deliberately framework-free: ~200 lines you can read
 * end-to-end, which is what you want running the gate of a residential
 * building at 3 a.m.
 */
final class App
{
    public const VERSION = '2.0.2';
    public const CODENAME = 'Kronos';

    private static ?App $instance = null;

    private Container $container;
    private string $root;
    private float $startedAt;
    private bool $booted = false;
    /** @var array<int, string> */
    private array $bootLog = [];

    private function __construct(string $root)
    {
        $this->root      = rtrim($root, '/\\');
        $this->container = new Container();
        $this->startedAt = microtime(true);
        Container::setInstance($this->container);
    }

    /**
     * @param Closure(Container, string): void|null $register extra service bindings
     */
    public static function boot(?Closure $register = null, ?string $root = null): self
    {
        $root ??= dirname(__DIR__, 3);
        $app = new self($root);
        self::$instance = $app;

        $app->container->set(self::class, $app);
        $app->container->set(Container::class, $app->container);
        $app->container->set('root', $root);

        $app->registerErrorHandling();

        if ($register !== null) {
            $register($app->container, $root);
        }

        $app->booted = true;

        return $app;
    }

    public static function instance(): ?App
    {
        return self::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    /** @return array<int, string> */
    public function bootLog(): array
    {
        return $this->bootLog;
    }

    public function make(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /* ─────────────────────────── request lifecycle ─────────────────────────── */

    /** Capture the current HTTP request, handle it and emit the response. */
    public function run(): never
    {
        $config  = $this->config();
        $request = Request::capture($config);

        try {
            $response = $this->handle($request);
        } catch (Throwable $e) {
            $response = $this->renderThrowable($e, $request);
        }

        $this->finish($request, $response);
        $response->emit();
        exit;
    }

    public function handle(Request $request): Response
    {
        $config  = $this->config();
        $requestId = $request->requestId();

        // Make the container aware of the current request as early as possible.
        $this->container->set(Request::class, $request);

        // Reset request-scoped state so handle() is safely re-entrant: the
        // test-suite, queue workers and any future long-running process reuse
        // one App instance for many requests and must never see a stale
        // principal, route or CSP nonce.
        foreach (['auth.user', 'auth.guard', 'auth.principal', 'api.principal', 'cspNonce', 'route'] as $scoped) {
            $this->container->forget($scoped);
        }
        if ($this->container->hasInstance(\BasIp\Auth\Guard::class)) {
            /** @var \BasIp\Auth\Guard $guard */
            $guard = $this->container->get(\BasIp\Auth\Guard::class);
            $guard->reset();
        }

        $session = $this->container->has(Session::class) ? $this->container->get(Session::class) : null;
        if ($session instanceof Session) {
            $session->start();
            $session->ageFlash();
        }

        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        $logger = $logger->withRequestId($requestId)->channel('access');
        $this->container->set('logger.access', $logger);

        // Resolve the route *before* the middleware pipeline: Authenticate,
        // Authorize, RateLimit and VerifyCsrf all read route options
        // (`auth`, `roles`, `permission`, `throttle`, `csrf_except`).
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $match  = $router->dispatch($request->method, $request->path);

        if ($match === null) {
            $allowed = $router->allowedMethodsFor($request->path);
            if ($allowed !== [] && !in_array($request->method, $allowed, true)) {
                throw new RoutingException('Method not allowed', 405, ['Allow' => implode(', ', $allowed)]);
            }

            throw new RoutingException('Страница не найдена', 404);
        }

        $request = $request->withRouteParams($match['params'])->withRequestId($requestId);
        $this->container->set(Request::class, $request);
        $this->container->set('route', $match['route']);

        $pipeline = new MiddlewarePipeline($this->container, $this->middlewareStack());

        return $pipeline->send($request, function (Request $request) use ($match): Response {
            return $this->callAction($match['route']['handler'], $request, $match['params']);
        });
    }

    /** @param array<int, class-string> $stack */
    private function middlewareStack(): array
    {
        return [
            \BasIp\Http\Middleware\TrustProxies::class,
            \BasIp\Http\Middleware\SecurityHeaders::class,
            \BasIp\Http\Middleware\MaintenanceMode::class,
            \BasIp\Http\Middleware\IpAllowlist::class,
            \BasIp\Http\Middleware\StartSession::class,
            \BasIp\Http\Middleware\VerifyCsrf::class,
            \BasIp\Http\Middleware\RateLimit::class,
            \BasIp\Http\Middleware\Authenticate::class,
            \BasIp\Http\Middleware\Authorize::class,
        ];
    }

    /**
     * Resolve a route handler: `[Controller::class, 'method']`, a closure or a
     * `Controller@method` string.
     *
     * @param mixed $handler
     * @param array<string, string> $params
     */
    private function callAction($handler, Request $request, array $params): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, ...array_values($params));

            return $this->toResponse($result, $request);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                if (!class_exists($class)) {
                    throw new RoutingException('Controller not found: ' . $class, 500);
                }
                $controller = $this->container->has($class)
                    ? $this->container->get($class)
                    : new $class($this->container);
            } else {
                $controller = $class;
            }
            if (!method_exists($controller, $method)) {
                throw new RoutingException(sprintf('Method %s::%s() does not exist.', $controller::class, $method), 500);
            }
            // Controllers receive the request plus the route parameters only;
            // services are resolved through the container they were given.
            $result = $controller->{$method}($request, ...array_values($params));

            return $this->toResponse($result, $request);
        }

        throw new RoutingException('Invalid route handler.', 500);
    }

    private function toResponse(mixed $result, Request $request): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }
        if ($result === null) {
            return Response::noContent();
        }

        return Response::html((string) $result);
    }

    private function finish(Request $request, Response $response): void
    {
        // X-Request-Id / X-Frame-Options / nosniff are applied by the
        // SecurityHeaders middleware (and by renderThrowable for error pages),
        // so only debug headers are added here.

        if ($this->config()->bool('app.debug')) {
            $response->header('X-Debug-Time-Ms', (string) round((microtime(true) - $this->startedAt) * 1000, 1), false);
            if ($this->container->has(Database::class)) {
                /** @var \BasIp\Db\Database $db */
                $db = $this->container->get(\BasIp\Db\Database::class);
                $stats = $db->stats();
                $response->header('X-Debug-Queries', $stats['queries'] . ' / ' . $stats['ms'] . 'ms', false);
            }
        }

        if ($this->container->has(Session::class)) {
            /** @var Session $session */
            $session = $this->container->get(Session::class);
            $session->touch();
        }
        if ($this->container->has(Logger::class)) {
            /** @var Logger $logger */
            $this->container->get(Logger::class)->close();
        }
    }

    /* ─────────────────────────── errors ─────────────────────────── */

    private function registerErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('log_errors_max_len', '4096');

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $request = $this->container->has(Request::class)
                ? $this->container->get(Request::class)
                : Request::make('GET', '/');
            $this->renderThrowable($e, $request)->emit();
            exit(1);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            $this->log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        });
    }

    public function renderThrowable(Throwable $e, Request $request): Response
    {
        $status = $e instanceof RoutingException ? $e->statusCode : 500;
        if ($status >= 500) {
            $this->logException($e);
        }

        $headers = $e instanceof RoutingException ? $e->headers : [];

        if ($request->expectsJson()) {
            $payload = [
                'ok'      => false,
                'error'   => $status >= 500 && !$this->config()->bool('app.debug') ? 'Внутренняя ошибка сервера' : $e->getMessage(),
                'status'  => $status,
                'request_id' => $request->requestId(),
            ];
            if ($this->config()->bool('app.debug') && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['file']      = $e->getFile() . ':' . $e->getLine();
                $payload['trace']     = array_slice(explode("\n", $e->getTraceAsString()), 0, 20);
            }

            return $this->applyBaselineHeaders(Response::json($payload, $status)->withHeaders($headers), $request);
        }

        try {
            /** @var View $view */
            $view = $this->container->get(View::class);
            $template = 'errors/' . $status;
            $view->shareMany([
                'basePath' => $request->basePath(),
                'path'     => $request->path,
                'status'   => $status,
                'message'  => $status >= 500 && !$this->config()->bool('app.debug')
                    ? 'Что-то пошло не так на стороне сервера.'
                    : $e->getMessage(),
                'requestId' => $request->requestId(),
                'exception' => $this->config()->bool('app.debug') && $status >= 500 ? $e : null,
                'user'     => $this->container->has('auth.user') ? $this->container->get('auth.user') : null,
                'cspNonce' => $this->container->has('cspNonce') ? $this->container->get('cspNonce') : '',
            ]);

            return $this->applyBaselineHeaders(Response::html($view->render($template), $status)->withHeaders($headers), $request);
        } catch (Throwable) {
            $debug = $this->config()->bool('app.debug')
                ? '<pre style="white-space:pre-wrap">' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES) . '</pre>'
                : '';

            return $this->applyBaselineHeaders(Response::html(
                '<!doctype html><html lang="ru"><meta charset="utf-8">'
                . '<title>' . $status . '</title>'
                . '<body style="font-family:system-ui,sans-serif;padding:3rem;text-align:center">'
                . '<h1 style="font-size:3rem;margin:0">' . $status . '</h1>'
                . '<p>' . htmlspecialchars($status >= 500 ? 'Внутренняя ошибка сервера' : $e->getMessage(), ENT_QUOTES) . '</p>'
                . $debug . '</body></html>',
                $status
            ), $request);
        }
    }

    /**
     * Error responses bypass the middleware pipeline, so the baseline security
     * headers are applied here — every response leaving the kernel carries
     * them regardless of the path it took.
     */
    private function applyBaselineHeaders(Response $response, Request $request): Response
    {
        $hasCacheControl = isset($response->headers()['Cache-Control']);

        $response->header('X-Request-Id', $request->requestId());
        $response->header('X-Frame-Options', $this->config()->string('security.headers.frame_options', 'SAMEORIGIN'));
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', $this->config()->string('security.headers.referrer_policy', 'strict-origin-when-cross-origin'));
        if (!$hasCacheControl) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        return $response;
    }

    public function logException(Throwable $e): void
    {
        try {
            /** @var Logger $logger */
            $logger = $this->container->get(Logger::class);
            $logger->channel('error')->exception($e);
        } catch (Throwable) {
            error_log('[BAS-IP] ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        try {
            /** @var Logger $logger */
            $this->container->get(Logger::class)->channel('error')->critical($message);
        } catch (Throwable) {
            error_log('[BAS-IP] ' . $message);
        }
    }

    /* ─────────────────────────── diagnostics ─────────────────────────── */

    /** @return array<string, mixed> */
    public function environment(): array
    {
        return [
            'version'     => self::VERSION,
            'codename'    => self::CODENAME,
            'php'         => PHP_VERSION,
            'sapi'        => PHP_SAPI,
            'os'          => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'extensions'  => array_values(array_filter(['curl', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'mbstring', 'openssl', 'sodium', 'json', 'intl', 'zip'], 'extension_loaded')),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'opcache'     => function_exists('opcache_get_status') ? (bool) (opcache_get_status(false)['opcache_enabled'] ?? false) : false,
            'timezone'    => date_default_timezone_get(),
            'root'        => $this->root,
            'uptime_ms'   => round((microtime(true) - $this->startedAt) * 1000, 1),
        ];
    }
}
