<?php

declare(strict_types=1);

namespace BasIp\Core;

/**
 * Regular-expression router with named routes, groups and middleware.
 *
 * Placeholder syntax:
 *   {id}        → [^/]+
 *   {id:\d+}    → custom constraint
 *   {*}         → trailing wildcard
 */
final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];

    /** @var array<string, array<string, mixed>> */
    private array $byName = [];

    /** @var array<int, string> */
    private array $groupPrefix = [];

    /** @var array<int, array<int, string>> */
    private array $groupMiddleware = [];

    /** @var array<int, array<string, string>> */
    private array $fallbacks = [];

    private string $currentAsPrefix = '';

    private ?string $matched = null;
    /** @var array<string, string> */
    private array $params = [];
    /** @var array<int, string> */
    private array $allowedMethods = [];

    public function get(string $path, mixed $handler, array $options = []): self    { return $this->add(['GET', 'HEAD'], $path, $handler, $options); }
    public function post(string $path, mixed $handler, array $options = []): self   { return $this->add(['POST'], $path, $handler, $options); }
    public function put(string $path, mixed $handler, array $options = []): self    { return $this->add(['PUT'], $path, $handler, $options); }
    public function patch(string $path, mixed $handler, array $options = []): self { return $this->add(['PATCH'], $path, $handler, $options); }
    public function delete(string $path, mixed $handler, array $options = []): self { return $this->add(['DELETE'], $path, $handler, $options); }
    public function any(string $path, mixed $handler, array $options = []): self    { return $this->add(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler, $options); }
    public function match(array $methods, string $path, mixed $handler, array $options = []): self { return $this->add($methods, $path, $handler, $options); }

    /**
     * @param array{prefix?: string, middleware?: array<int, string>|string, as?: string} $attributes
     */
    public function group(array $attributes, callable $callback): self
    {
        $prefix = rtrim((string) ($attributes['prefix'] ?? ''), '/');
        $this->groupPrefix[]     = $prefix;
        $middleware = (array) ($attributes['middleware'] ?? []);
        $this->groupMiddleware[] = $middleware;
        $as = (string) ($attributes['as'] ?? '');
        $previousAs = $this->currentAsPrefix;
        $this->currentAsPrefix = $previousAs . $as;

        $callback($this);

        $this->currentAsPrefix = $previousAs;
        array_pop($this->groupPrefix);
        array_pop($this->groupMiddleware);

        return $this;
    }

    public function add(array $methods, string $path, mixed $handler, array $options = []): self
    {
        $prefix = implode('', $this->groupPrefix);
        $full   = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $full   = $full === '//' ? '/' : $full;

        $middleware = [];
        foreach ($this->groupMiddleware as $group) {
            $middleware = array_merge($middleware, $group);
        }
        $middleware = array_values(array_unique(array_merge($middleware, (array) ($options['middleware'] ?? []))));

        $name = $options['name'] ?? null;
        if ($name === null) {
            $name = strtolower(trim($this->currentAsPrefix . str_replace(['/', '{', '}'], ['.', '', ''], $full), '.'));
        } else {
            $name = $this->currentAsPrefix . $name;
        }

        $route = [
            'methods'    => array_map('strtoupper', $methods),
            'path'       => $full,
            'regex'      => $this->compile($full),
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => $name,
            'options'    => $options,
        ];

        $this->routes[]      = $route;
        $this->byName[$name] = $route;

        return $this;
    }

    public function fallback(string $method, mixed $handler): self
    {
        $this->fallbacks[$method] = is_string($handler) ? ['handler' => $handler] : $handler;

        return $this;
    }

    /**
     * @return array{route: array<string, mixed>, params: array<string, string>}|null
     */
    public function dispatch(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path   = '/' . trim(rawurldecode($path), '/');
        $path   = $path === '//' ? '/' : $path;
        $this->allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $this->allowedMethods = array_values(array_unique(array_merge($this->allowedMethods, $route['methods'])));

                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $this->matched = $route['name'];
            $this->params  = $params;

            return ['route' => $route, 'params' => $params];
        }

        return null;
    }

    public function currentRouteName(): ?string
    {
        return $this->matched;
    }

    /** @return array<string, string> */
    public function currentParams(): array
    {
        return $this->params;
    }

    /** @return array<int, string> */
    public function allowedMethodsFor(string $path): array
    {
        $this->dispatch('GET', $path);

        return $this->allowedMethods;
    }

    /**
     * Build a URL for a named route.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function url(string $name, array $params = [], array $query = [], string $basePath = ''): string
    {
        $route = $this->byName[$name] ?? null;
        if ($route === null) {
            // Unknown route — assume it is a literal path.
            $url = '/' . ltrim($name, '/');
        } else {
            $url = (string) preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}|\{\*\}/', static function (array $m) use (&$params): string {
                $key = $m[1] ?? '*';
                if (!array_key_exists($key, $params)) {
                    return '';
                }
                $value = (string) $params[$key];
                unset($params[$key]);

                return rawurlencode($value);
            }, (string) $route['path']);
        }

        // Left-over params become the query string.
        $query = array_merge($params, $query);
        $url = rtrim($basePath, '/') . '/' . ltrim($url, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url === '' ? '/' : $url;
    }

    /** @return array<string, mixed> */
    public function routeByName(string $name): array
    {
        return $this->byName[$name] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function compile(string $path): string
    {
        if ($path === '/') {
            return '#^/$#';
        }
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}|\{\*\}/',
            static function (array $m): string {
                if (($m[0] ?? '') === '{*}') {
                    return '(?P<wildcard>.+)';
                }
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $path
        ) ?? $path;

        return '#^' . $pattern . '$#u';
    }
}
