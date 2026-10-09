<?php

declare(strict_types=1);

namespace BasIp\Db;

/**
 * Mutable wrapper for `EXISTS (SELECT …)` sub-queries.
 *
 * Same reason as {@see WhereGroup}: {@see Query} is immutable, so a closure
 * that builds a sub-select without returning it would silently produce an
 * empty `EXISTS ()`.
 */
final class SubQuery
{
    public function __construct(private Query $query)
    {
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->query = $this->query->from($table, $alias);

        return $this;
    }

    public function select(string ...$columns): self
    {
        $this->query = $this->query->select(...$columns);

        return $this;
    }

    public function selectRaw(string $sql): self
    {
        $this->query = $this->query->selectRaw($sql);

        return $this;
    }

    public function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        $this->query = $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }

    public function orWhere(mixed $column, mixed $operator = null, mixed $value = null): self
    {
        $this->query = $this->query->orWhere($column, $operator, $value);

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereRaw($sql, $bindings, $boolean);

        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereNull($column, $boolean);

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereNotNull($column, $boolean);

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        $this->query = $this->query->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    public function whereGroup(callable $callback, string $boolean = 'AND'): self
    {
        $this->query = $this->query->where($callback, null, null, $boolean);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query = $this->query->limit($limit);

        return $this;
    }

    public function toQuery(): Query
    {
        return $this->query;
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    public function compile(): array
    {
        return $this->query->compileSelect();
    }
}
