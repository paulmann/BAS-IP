<?php

declare(strict_types=1);

namespace BasIp\Db;

/**
 * Mutable condition collector for grouped `WHERE (…)`.
 *
 * {@see Query} is immutable — every method returns a clone. That is convenient
 * for building a query step by step, but fatal inside a closure:
 *
 *     $q->where(function ($sub) { $sub->where('a', 1)->orWhere('b', 2); });
 *
 * …silently produces **no** condition, because the clones are thrown away.
 * A dropped filter on a security-relevant query is exactly the kind of bug that
 * is invisible in tests that only check "did it run".
 *
 * This wrapper mutates instead, so the closure style works and a missing
 * `return` can never drop a condition.
 */
final class WhereGroup
{
    public function __construct(private Query $query)
    {
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

    public function orWhereNull(string $column): self
    {
        $this->query = $this->query->whereNull($column, 'OR');

        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        $this->query = $this->query->whereNotNull($column, 'OR');

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        $this->query = $this->query->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereIn($column, $values, $boolean, true);

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function orWhereIn(string $column, array $values): self
    {
        $this->query = $this->query->whereIn($column, $values, 'OR');

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereBetween($column, $from, $to, $boolean);

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereRaw($sql, $bindings, $boolean);

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->query = $this->query->whereRaw($sql, $bindings, 'OR');

        return $this;
    }

    /** @param array<int, string> $columns */
    public function whereSearch(array $columns, ?string $term, string $boolean = 'AND'): self
    {
        $this->query = $this->query->whereSearch($columns, $term, $boolean);

        return $this;
    }

    public function whereExists(callable $callback, string $boolean = 'AND', bool $not = false): self
    {
        $this->query = $this->query->whereExists($callback, $boolean, $not);

        return $this;
    }

    public function orWhereExists(callable $callback, bool $not = false): self
    {
        $this->query = $this->query->whereExists($callback, 'OR', $not);

        return $this;
    }

    /** Nested group. */
    public function whereGroup(callable $callback, string $boolean = 'AND'): self
    {
        $this->query = $this->query->where($callback, null, null, $boolean);

        return $this;
    }

    public function orGroup(callable $callback): self
    {
        return $this->whereGroup($callback, 'OR');
    }

    /** The accumulated conditions as a Query (used by the compiler). */
    public function toQuery(): Query
    {
        return $this->query;
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    public function compile(): array
    {
        return $this->query->compileGroup();
    }
}
