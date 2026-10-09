<?php

declare(strict_types=1);

namespace BasIp\Core;

use Closure;
use RuntimeException;

/**
 * Minimal service container.
 *
 * Deliberately tiny — no reflection magic, no annotations. Services are
 * registered as closures in `src/bootstrap.php` and resolved lazily & once
 * (shared) unless `factory()` is used.
 *
 * @phpstan-type Factory Closure(Container): mixed
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, Closure(Container): mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, array<int, callable>> */
    private array $extenders = [];

    public static function instance(): Container
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /** @param Closure(Container): mixed $factory */
    public function bind(string $id, Closure $factory, bool $shared = true): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id]   = $shared;
        unset($this->instances[$id]);
    }

    /** @param Closure(Container): mixed $factory */
    public function factory(string $id, Closure $factory): void
    {
        $this->bind($id, $factory, false);
    }

    /** Register an already-built object. */
    public function set(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        $this->shared[$id]    = true;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    /** True when a shared instance was already built (regardless of bindings). */
    public function hasInstance(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    /**
     * Drop a built instance, keeping the binding. The next {@see get()} will
     * re-run the factory. Used to reset request-scoped services so that
     * {@see App::handle()} stays re-entrant (tests, queue workers, CLI daemons).
     */
    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }

    public function extend(string $id, callable $extender): void
    {
        $this->extenders[$id][] = $extender;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->bindings[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered in the container.', $id));
        }

        $service = ($this->bindings[$id])($this);
        foreach ($this->extenders[$id] ?? [] as $extender) {
            $service = $extender($service, $this);
        }

        if ($this->shared[$id] ?? true) {
            $this->instances[$id] = $service;
        }

        return $service;
    }

    /** @template T @param class-string<T> $id @return T */
    public function typed(string $id): mixed
    {
        /** @var T */
        return $this->get($id);
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        $ids = array_unique(array_merge(array_keys($this->bindings), array_keys($this->instances)));
        sort($ids);

        return $ids;
    }

    public function flush(): void
    {
        $this->instances = [];
    }
}
