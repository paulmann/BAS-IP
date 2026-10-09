<?php

declare(strict_types=1);

namespace BasIp\Db;

use BasIp\Support\Paginator;
use InvalidArgumentException;
use PDO;

/**
 * Small, dependency-free query builder.
 *
 * It only ever produces *parameterised* SQL: every user-supplied value goes
 * through a `?` placeholder, every identifier is validated against
 * `^[A-Za-z0-9_.$]+$` and quoted per driver. `whereRaw()` exists for the rare
 * dialect-specific expression and must never receive user input directly.
 */
final class Query
{
    private string $table;
    /** @var array<int, string> */
    private array $columns = ['*'];
    private bool $distinct = false;
    /** @var array<int, string> */
    private array $joins = [];
    /** @var array<int, string> */
    private array $wheres = [];
    /** @var array<int, mixed> */
    private array $bindings = ['select' => [], 'join' => [], 'where' => [], 'having' => []];
    /** @var array<int, string> */
    private array $groups = [];
    /** @var array<int, string> */
    private array $havings = [];
    /** @var array<int, string> */
    private array $orders = [];
    private ?int $limit = null;
    private int $offset = 0;
    private bool $forUpdate = false;
    private ?string $unionSql = null;
    /** @var array<int, mixed> */
    private array $unionBindings = [];

    public function __construct(private readonly Database $db, string $table)
    {
        $this->table = $table;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $clone = clone $this;
        $clone->table = $alias !== null && $alias !== '' ? $table . ' ' . $alias : $table;

        return $clone;
    }

    /**
     * Render a `table [alias]` fragment, quoting each part separately and
     * applying the configured prefix to the table only.
     */
    private function compileFrom(string $table): string
    {
        $parts = preg_split('/\s+(?:as\s+)?/i', trim($table), 2) ?: [$table];
        $sql = $this->db->quoteIdentifier($this->db->prefixTable($parts[0]));
        if (isset($parts[1]) && $parts[1] !== '') {
            $sql .= ' ' . $this->db->quoteIdentifier($parts[1]);
        }

        return $sql;
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns === [] ? ['*'] : array_values($columns);

        return $clone;
    }

    public function addSelect(string ...$columns): self
    {
        $clone = clone $this;
        if ($clone->columns === ['*']) {
            $clone->columns = [];
        }
        $clone->columns = array_values(array_merge($clone->columns, $columns));

        return $clone;
    }

    public function distinct(bool $distinct = true): self
    {
        $clone = clone $this;
        $clone->distinct = $distinct;

        return $clone;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $clone = clone $this;
        $this->assertIdentifier($first);
        $this->assertIdentifier($second);
        // Accept both `table` and `table alias` (also `table AS alias`).
        $tableSql = preg_match('/^([a-zA-Z0-9_]+)\s+(?:as\s+)?([a-zA-Z0-9_]+)$/i', trim($table), $m)
            ? $this->db->quoteIdentifier($this->db->prefixTable($m[1])) . ' ' . $this->db->quoteIdentifier($m[2])
            : $this->db->quoteIdentifier($this->db->prefixTable($table));
        $clone->joins[] = sprintf('%s JOIN %s ON %s %s %s', strtoupper($type), $tableSql, $this->wrap($first), $this->validOperator($operator), $this->wrap($second));

        return $clone;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /** Explicit INNER JOIN — alias of {@see join()} for readability. */
    public function innerJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'INNER');
    }

    public function crossJoin(string $table): self
    {
        $clone = clone $this;
        $clone->joins[] = 'CROSS JOIN ' . $this->db->quoteIdentifier($this->db->prefixTable($table));

        return $clone;
    }

    /**
     * Flexible where:
     *   where('id', 5)
     *   where('id', '>', 5)
     *   where(['is_active' => 1, 'role' => 'admin'])
     *   where(fn (Query $q) => $q->where('a', 1)->orWhere('b', 2))
     */
    public function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        $clone = clone $this;

        if (is_array($column)) {
            foreach ($column as $key => $val) {
                if (is_int($key) && is_array($val) && count($val) >= 2) {
                    $clone = $clone->where($val[0], $val[1] ?? '=', $val[2] ?? null, $boolean);
                } else {
                    $clone = $clone->where((string) $key, '=', $val, $boolean);
                }
            }

            return $clone;
        }

        if ($column instanceof \Closure) {
            // Mutable collector: a closure that forgets to `return` still works,
            // so a filter can never be silently dropped.
            $group = new WhereGroup(new self($this->db, $this->table));
            $column($group);
            [$sql, $bindings] = $group->compile();
            if (trim($sql) !== '') {
                $clone->wheres[] = $boolean . ' (' . $sql . ')';
                $clone->bindings['where'] = array_merge($clone->bindings['where'], $bindings);
            }

            return $clone;
        }

        $column = (string) $column;
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }
        $operator = $this->validOperator((string) $operator);

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            $clone->wheres[] = $boolean . ' ' . $this->wrap($column) . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');

            return $clone;
        }

        if (strtoupper($operator) === 'LIKE' || strtoupper($operator) === 'NOT LIKE') {
            $operator = $this->db->isPgsql() ? strtoupper($operator[0] === 'N' ? 'NOT ILIKE' : 'ILIKE') : strtoupper($operator);
        }

        $clone->wheres[]   = $boolean . ' ' . $this->wrap($column) . ' ' . $operator . ' ?';
        $clone->bindings['where'][] = $value;

        return $clone;
    }

    public function orWhere(mixed $column, mixed $operator = null, mixed $value = null): self
    {
        return func_num_args() === 2
            ? $this->where($column, $operator, null, 'OR')
            : $this->where($column, $operator, $value, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        $clone = clone $this;
        $values = array_values($values);
        if ($values === []) {
            $clone->wheres[] = $boolean . ' ' . ($not ? '1 = 1' : '1 = 0');

            return $clone;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $clone->wheres[] = $boolean . ' ' . $this->wrap($column) . ($not ? ' NOT IN ' : ' IN ') . '(' . $placeholders . ')';
        $clone->bindings['where'] = array_merge($clone->bindings['where'], $values);

        return $clone;
    }

    /** @param array<int, mixed> $values */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): self
    {
        $clone = clone $this;
        $clone->wheres[] = $boolean . ' ' . $this->wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL');

        return $clone;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): self
    {
        $clone = clone $this;
        $clone->wheres[] = $boolean . ' ' . $this->wrap($column) . ' BETWEEN ? AND ?';
        $clone->bindings['where'][] = $from;
        $clone->bindings['where'][] = $to;

        return $clone;
    }

    /**
     * Free-text-ish search across several columns (case-insensitive).
     *
     * @param array<int, string> $columns
     */
    public function whereSearch(array $columns, ?string $term, string $boolean = 'AND'): self
    {
        $term = trim((string) $term);
        if ($term === '' || $columns === []) {
            return $this;
        }
        $clone = clone $this;
        $pattern = '%' . self::escapeLike($term) . '%';

        return $clone->where(static function (WhereGroup $group) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $group->orWhere($column, 'like', $pattern);
            }
        }, null, null, $boolean);
    }

    /** @param array<int, mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $clone = clone $this;
        $clone->wheres[] = $boolean . ' (' . $sql . ')';
        $clone->bindings['where'] = array_merge($clone->bindings['where'], $bindings);

        return $clone;
    }

    public function whereExists(callable $callback, string $boolean = 'AND', bool $not = false): self
    {
        $sub = new SubQuery(new self($this->db, ''));
        $result = $callback($sub);
        if ($result instanceof self) {
            $sub = new SubQuery($result);
        } elseif ($result instanceof SubQuery) {
            $sub = $result;
        }
        [$sql, $bindings] = $sub->compile();
        $clone = clone $this;
        $clone->wheres[] = $boolean . ' ' . ($not ? 'NOT EXISTS' : 'EXISTS') . ' (' . $sql . ')';
        $clone->bindings['where'] = array_merge($clone->bindings['where'], $bindings);

        return $clone;
    }

    public function orWhereExists(callable $callback, bool $not = false): self
    {
        return $this->whereExists($callback, 'OR', $not);
    }

    public function whereNotExists(callable $callback, string $boolean = 'AND'): self
    {
        return $this->whereExists($callback, $boolean, true);
    }

    public function groupBy(string ...$columns): self
    {
        $clone = clone $this;
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
            $clone->groups[] = $this->wrap($column);
        }

        return $clone;
    }

    public function having(string $sql, array $bindings = []): self
    {
        $clone = clone $this;
        $clone->havings[] = $sql;
        $clone->bindings['having'] = array_merge($clone->bindings['having'], $bindings);

        return $clone;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        if ($column === '' ) {
            return $clone;
        }
        // Allow "table.column" and simple expressions like "COALESCE(a, b)".
        if (preg_match('/^[A-Za-z0-9_.$]+$/', $column)) {
            $column = $this->wrap($column);
        } elseif (!preg_match('/^[A-Za-z0-9_.,()\s\'"$`]+$/', $column)) {
            throw new InvalidArgumentException('Invalid ORDER BY expression: ' . $column);
        }
        $clone->orders[] = $column . ' ' . $direction;

        return $clone;
    }

    public function orderByRaw(string $sql): self
    {
        $clone = clone $this;
        $clone->orders[] = $sql;

        return $clone;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(?int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit === null ? null : max(0, $limit);

        return $clone;
    }

    public function take(?int $limit): self
    {
        return $this->limit($limit);
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = max(0, $offset);

        return $clone;
    }

    public function forPage(int $page, int $perPage): self
    {
        $page = max(1, $page);

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    public function lockForUpdate(): self
    {
        $clone = clone $this;
        $clone->forUpdate = !$this->db->isSqlite();

        return $clone;
    }

    /** @param array<int, mixed> $bindings */
    public function union(string $sql, array $bindings = [], bool $all = false): self
    {
        $clone = clone $this;
        $clone->unionSql = ($all ? 'UNION ALL ' : 'UNION ') . $sql;
        $clone->unionBindings = $bindings;

        return $clone;
    }

    /* ─────────────────────────── execution ─────────────────────────── */

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();

        return $this->db->select($sql, $bindings);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->get();
    }

    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function find(int|string $id, string $column = 'id'): ?array
    {
        return $this->where($column, '=', $id)->first();
    }

    public function firstOrFail(string $message = 'Record not found'): array
    {
        $row = $this->first();
        if ($row === null) {
            throw new \RuntimeException($message);
        }

        return $row;
    }

    public function value(string $column, mixed $default = null): mixed
    {
        $row = $this->select($column)->first();
        if ($row === null) {
            return $default;
        }

        return reset($row) ?: $default;
    }

    /** @return array<int, mixed> */
    public function pluck(string $column, ?string $key = null): array
    {
        $columns = $key === null ? [$column] : [$key, $column];
        $rows = $this->select(...$columns)->get();
        $out = [];
        foreach ($rows as $row) {
            if ($key === null) {
                $out[] = $row[$column] ?? null;
            } else {
                $out[(string) ($row[$key] ?? '')] = $row[$column] ?? null;
            }
        }

        return $out;
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate('COALESCE_SUM', $column);
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function aggregate(string $function, string $column = '*'): mixed
    {
        if ($function === 'COALESCE_SUM') {
            $expression = sprintf('COALESCE(SUM(%s), 0)', $column === '*' ? '*' : $this->wrap($column));
        } else {
            $expression = sprintf('%s(%s)', strtoupper($function), $column === '*' ? '*' : $this->wrap($column));
        }
        $clone = clone $this;
        $clone->columns = [$expression . ' AS aggregate'];
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = 0;

        // With GROUP BY / HAVING / DISTINCT the aggregate is computed *per
        // group*, so a bare COUNT(*) would return the size of the first group
        // instead of the number of rows. Wrap the query and aggregate the
        // per-group results: COUNT → number of groups, SUM → overall total.
        if ($clone->groups !== [] || $clone->havings !== [] || $clone->distinct) {
            [$innerSql, $bindings] = $clone->compileSelect();
            $outerFunc = $function === 'COALESCE_SUM' ? 'SUM' : strtoupper($function);
            $outer = $function === 'COALESCE_SUM'
                ? 'COALESCE(SUM(aggregate), 0)'
                : sprintf('%s(aggregate)', $outerFunc);
            $sql = sprintf('SELECT %s AS aggregate FROM (%s) %s', $outer, $innerSql, $this->db->quoteIdentifier('basip_agg'));
            $row = $this->db->selectOne($sql, $bindings);

            return $row === null ? 0 : reset($row);
        }

        [$sql, $bindings] = $clone->compileSelect();
        $row = $this->db->selectOne($sql, $bindings);

        return $row === null ? 0 : reset($row);
    }

    public function paginate(int $perPage = 25, int $page = 1, string $pageName = 'page'): Paginator
    {
        $page    = max(1, $page);
        $perPage = max(1, min(1000, $perPage));

        $countQuery = clone $this;
        $countQuery->orders = [];
        $countQuery->limit  = null;
        $countQuery->offset = 0;
        $total = $countQuery->count();

        $items = $this->forPage($page, $perPage)->get();

        return new Paginator($items, $total, $perPage, $page, $pageName);
    }

    /** @param callable(array<int, array<string, mixed>>): void $callback */
    public function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $rows = $this->forPage($page, $size)->get();
            if ($rows === []) {
                break;
            }
            $callback($rows);
            $page++;
        } while (count($rows) === $size);
    }

    /* ─────────────────────────── writes ─────────────────────────── */

    /** @param array<string, mixed> $values */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $columns = array_keys($values);
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->db->quoteIdentifier($this->db->prefixTable($this->table)),
            implode(', ', array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        return $this->db->statement($sql, array_map(static fn ($v) => self::normaliseValue($v), array_values($values))) >= 0;
    }

    /**
     * Insert and return the new primary key.
     *
     * PostgreSQL identity columns do not play well with `PDO::lastInsertId()`,
     * so we use the native `RETURNING` clause there.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $returning = 'id'): int
    {
        $columns = array_keys($values);
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }
        $this->assertIdentifier($returning);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->db->quoteIdentifier($this->db->prefixTable($this->table)),
            implode(', ', array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $bindings = array_map(static fn ($v) => self::normaliseValue($v), array_values($values));

        if ($this->db->isPgsql()) {
            $sql .= ' RETURNING ' . $this->db->quoteIdentifier($returning);
            $row = $this->db->selectOne($sql, $bindings);

            return (int) ($row[$returning] ?? 0);
        }

        return (int) $this->db->insert($sql, $bindings);
    }

    /**
     * Bulk insert in batches (portable multi-row VALUES).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertBatch(array $rows, int $batchSize = 250): int
    {
        $rows = array_values(array_filter($rows, static fn ($r) => is_array($r) && $r !== []));
        if ($rows === []) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }
        $inserted = 0;
        $quotedColumns = implode(', ', array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), $columns));
        $table = $this->db->quoteIdentifier($this->db->prefixTable($this->table));

        foreach (array_chunk($rows, max(1, $batchSize)) as $batch) {
            $placeholders = [];
            $bindings = [];
            foreach ($batch as $row) {
                $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                foreach ($columns as $column) {
                    $bindings[] = self::normaliseValue($row[$column] ?? null);
                }
            }
            $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $table, $quotedColumns, implode(', ', $placeholders));
            $inserted += $this->db->statement($sql, $bindings);
        }

        return $inserted;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            $this->assertIdentifier((string) $column);
            if ($value instanceof Expression) {
                $sets[] = $this->db->quoteIdentifier((string) $column) . ' = ' . $value->sql;
                $bindings = array_merge($bindings, $value->bindings);
                continue;
            }
            $sets[] = $this->db->quoteIdentifier((string) $column) . ' = ?';
            $bindings[] = self::normaliseValue($value);
        }
        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql = sprintf('UPDATE %s SET %s', $this->db->quoteIdentifier($this->db->prefixTable($this->table)), implode(', ', $sets));
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return $this->db->statement($sql, array_merge($bindings, $whereBindings));
    }

    public function increment(string $column, float|int $amount = 1, array $extra = []): int
    {
        $extra[$column] = new Expression($this->db->quoteIdentifier($column) . ' + ?', [$amount]);

        return $this->update($extra);
    }

    public function decrement(string $column, float|int $amount = 1, array $extra = []): int
    {
        $extra[$column] = new Expression($this->db->quoteIdentifier($column) . ' - ?', [$amount]);

        return $this->update($extra);
    }

    public function delete(): int
    {
        [$whereSql, $bindings] = $this->compileWheres();
        $sql = 'DELETE FROM ' . $this->db->quoteIdentifier($this->db->prefixTable($this->table));
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return $this->db->statement($sql, $bindings);
    }

    public function truncate(): void
    {
        $table = $this->db->quoteIdentifier($this->db->prefixTable($this->table));
        if ($this->db->isPgsql()) {
            $this->db->statement('TRUNCATE TABLE ' . $table . ' RESTART IDENTITY');

            return;
        }
        if ($this->db->isMySql()) {
            $this->db->statement('TRUNCATE TABLE ' . $table);

            return;
        }
        $this->db->statement('DELETE FROM ' . $table);
        $this->db->statement("DELETE FROM sqlite_sequence WHERE name = ?", [$this->db->prefixTable($this->table)]);
    }

    /**
     * Portable upsert.
     *
     * @param array<string, mixed> $values
     * @param array<int, string>   $uniqueColumns columns forming the conflict target
     * @param array<int, string>|null $updateColumns defaults to every non-unique column
     */
    public function upsert(array $values, array $uniqueColumns, ?array $updateColumns = null): int
    {
        $columns = array_keys($values);
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }
        $updateColumns ??= array_values(array_diff($columns, $uniqueColumns));
        $bindings = array_map(static fn ($v) => self::normaliseValue($v), array_values($values));
        $table = $this->db->quoteIdentifier($this->db->prefixTable($this->table));
        $quoted = implode(', ', array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        if ($this->db->isMySql()) {
            if ($updateColumns === []) {
                $sql = sprintf('INSERT IGNORE INTO %s (%s) VALUES (%s)', $table, $quoted, $placeholders);

                return $this->db->statement($sql, $bindings);
            }
            $sets = implode(', ', array_map(
                fn ($c) => sprintf('%s = VALUES(%s)', $this->db->quoteIdentifier((string) $c), $this->db->quoteIdentifier((string) $c)),
                $updateColumns
            ));
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', $table, $quoted, $placeholders, $sets);

            return $this->db->statement($sql, $bindings);
        }

        // PostgreSQL / SQLite share the standard `ON CONFLICT` syntax.
        $target = implode(', ', array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), $uniqueColumns));
        if ($updateColumns === []) {
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING', $table, $quoted, $placeholders, $target);

            return $this->db->statement($sql, $bindings);
        }
        $sets = implode(', ', array_map(
            fn ($c) => sprintf('%s = EXCLUDED.%s', $this->db->quoteIdentifier((string) $c), $this->db->quoteIdentifier((string) $c)),
            $updateColumns
        ));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s', $table, $quoted, $placeholders, $target, $sets);

        return $this->db->statement($sql, $bindings);
    }

    /* ─────────────────────────── compilation ─────────────────────────── */

    /** @return array{0: string, 1: array<int, mixed>} */
    public function toSql(): array
    {
        return $this->compileSelect();
    }

    public function toSqlString(): string
    {
        [$sql] = $this->compileSelect();

        return $sql;
    }

    /** @return array<int, mixed> */
    public function bindings(): array
    {
        return array_merge($this->bindings['select'], $this->bindings['join'], $this->bindings['where'], $this->bindings['having'], $this->unionBindings);
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    /** Compile the full SELECT (also used by {@see SubQuery}). */
    public function compileSelect(): array
    {
        $sql = ($this->distinct ? 'SELECT DISTINCT ' : 'SELECT ') . $this->compileColumns();
        if ($this->table !== '') {
            $sql .= ' FROM ' . $this->compileFrom($this->table);
        }
        $bindings = $this->bindings['select'];

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
            $bindings = array_merge($bindings, $this->bindings['join']);
        }
        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }
        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
            $bindings = array_merge($bindings, $this->bindings['having']);
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        $sql .= $this->db->limitOffset($this->limit, $this->offset);
        if ($this->forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        if ($this->unionSql !== null) {
            $sql .= ' ' . $this->unionSql;
            $bindings = array_merge($bindings, $this->unionBindings);
        }

        return [$sql, $bindings];
    }

    private function compileColumns(): string
    {
        $out = [];
        foreach ($this->columns as $column) {
            if ($column instanceof Expression) {
                $out[] = $column->sql;
                continue;
            }
            $column = (string) $column;
            // Aliases and functions are passed through, bare identifiers are quoted.
            if (preg_match('/^[A-Za-z0-9_.$]+$/', $column)) {
                $out[] = $this->wrap($column);
            } else {
                $out[] = $column;
            }
        }

        return implode(', ', $out === [] ? ['*'] : $out);
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    /**
     * Compile the accumulated conditions of a group (used by {@see WhereGroup}).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileGroup(): array
    {
        return $this->compileWheres();
    }

    private function compileWheres(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }
        $parts = [];
        foreach ($this->wheres as $index => $where) {
            $boolean = strtoupper((string) (explode(' ', $where, 2)[0] ?? 'AND'));
            $rest = substr($where, strlen($boolean) + 1);
            $parts[] = ($index === 0 ? '' : $boolean . ' ') . $rest;
        }

        return [implode(' ', $parts), $this->bindings['where']];
    }

    private function wrap(string $column): string
    {
        if (str_contains($column, '(') || str_contains($column, ' ') || $column === '*') {
            return $column;
        }

        return $this->db->quoteIdentifier($column);
    }

    private function assertIdentifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_.$ ]+$/', $value)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $value);
        }
    }

    private function validOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];
        if (!in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException('Invalid SQL operator: ' . $operator);
        }

        return $operator;
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function normaliseValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($value instanceof Expression) {
            return $value->sql;
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }

        return $value;
    }
}
