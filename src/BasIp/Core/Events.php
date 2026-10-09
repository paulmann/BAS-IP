<?php

declare(strict_types=1);

namespace BasIp\Core;

/**
 * Synchronous event dispatcher.
 *
 * Used to decouple side-effects: `gate.opened` → write audit + notify +
 * statistics; `payment.recorded` → recalculate balance + notify resident.
 */
final class Events
{
    /** @var array<string, array<int, array{callable, int}>> */
    private array $listeners = [];

    public function on(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = ['fn' => $listener, 'p' => $priority];
        usort($this->listeners[$event], static fn ($a, $b) => $b['p'] <=> $a['p']);
    }

    public function off(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /** Dispatch and return the (possibly modified) payload. */
    public function dispatch(string $event, mixed $payload = null): mixed
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $result = ($listener['fn'])($payload, $event);
            if ($result !== null) {
                $payload = $result;
            }
        }
        foreach ($this->listeners['*'] ?? [] as $listener) {
            ($listener['fn'])($payload, $event);
        }

        return $payload;
    }

    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) || isset($this->listeners['*']);
    }

    /** @return array<int, string> */
    public function events(): array
    {
        $names = array_keys($this->listeners);
        sort($names);

        return $names;
    }
}
