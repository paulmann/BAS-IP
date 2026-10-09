<?php

declare(strict_types=1);

namespace BasIp\Console;

use BasIp\Core\Container;

/**
 * A console command definition.
 *
 * Commands are declared as data + a handler closure (see `Console/commands*.php`)
 * which keeps the CLI surface readable in one place and makes every command
 * trivially testable by calling `$handler($input, $output, $container)`.
 */
final class Command
{
    /**
     * @param array<string, string> $options  name => description
     * @param array<string, string> $arguments name => description
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $usage,
        public readonly array $options,
        public readonly array $arguments,
        public readonly string $group,
        /** @var callable(Input, Output, Container): int */
        public $handler,
        public readonly bool $needsDatabase = true,
        public readonly bool $hidden = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'usage' => $this->usage,
            'group' => $this->group,
            'options' => $this->options,
            'arguments' => $this->arguments,
        ];
    }
}
