<?php

declare(strict_types=1);

namespace BasIp\Console;

/**
 * argv parser: `command sub --option=value --flag positional`.
 */
final class Input
{
    /** @var array<int, string> */
    private array $arguments = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    private string $command = '';

    /** @param array<int, string> $argv */
    public function __construct(array $argv)
    {
        array_shift($argv);            // script name
        $this->command = (string) (array_shift($argv) ?? '');

        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $this->options[$name] = $value;
                } else {
                    $this->options[$body] = true;
                }
                continue;
            }
            if (str_starts_with($token, '-') && $token !== '-') {
                $this->options[substr($token, 1)] = true;
                continue;
            }
            $this->arguments[] = $token;
        }
    }

    public function command(): string
    {
        return $this->command;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return array<int, string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->options[$name] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', 'y'], true);
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->options[$name] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $name, float $default = 0.0): float
    {
        $value = str_replace(',', '.', (string) ($this->options[$name] ?? $default));

        return is_numeric($value) ? (float) $value : $default;
    }

    public function str(string $name, string $default = ''): string
    {
        $value = $this->options[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string, string|bool> */
    public function options(): array
    {
        return $this->options;
    }
}
