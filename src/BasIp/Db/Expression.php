<?php

declare(strict_types=1);

namespace BasIp\Db;

/**
 * Raw SQL fragment with its own bindings — use with `Query::update()`.
 */
final class Expression
{
    /** @param array<int, mixed> $bindings */
    public function __construct(public readonly string $sql, public readonly array $bindings = [])
    {
    }
}
