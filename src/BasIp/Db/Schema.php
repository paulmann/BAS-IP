<?php

declare(strict_types=1);

namespace BasIp\Db;

use BasIp\Core\Logger;
use Closure;
use RuntimeException;

/**
 * Portable DDL builder.
 *
 * One migration file describes the schema once and the builder emits the right
 * dialect for MySQL/MariaDB, PostgreSQL and SQLite. Types are mapped through
 * {@see Database} so adding a driver means touching a single class.
 */
final class Schema
{
    public function __construct(private readonly Database $db, private readonly ?Logger $logger = null)
    {
    }

    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($this->db, $table);
        $callback($blueprint);
        $sql = $blueprint->toCreateSql();
        $this->db->statement($sql);
        foreach ($blueprint->postCreate() as $statement) {
            $this->db->statement($statement);
        }
        $this->logger?->debug('Schema: created table ' . $table, ['channel' => 'app']);
    }

    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($this->db, $table);
        $callback($blueprint);
        foreach ($blueprint->toAlterSql() as $sql) {
            $this->db->statement($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->db->statement('DROP TABLE ' . $this->db->quoteIdentifier($this->db->prefixTable($table)));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->statement('DROP TABLE IF EXISTS ' . $this->db->quoteIdentifier($this->db->prefixTable($table)));
    }

    public function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->db->columns($table), true);
    }

    public function rename(string $from, string $to): void
    {
        $this->db->statement(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->db->quoteIdentifier($this->db->prefixTable($from)),
            $this->db->quoteIdentifier($this->db->prefixTable($to))
        ));
    }

    /** Raw DDL escape hatch. */
    public function raw(string $sql): void
    {
        $this->db->statement($sql);
    }

    /** Execute a multi-statement SQL script (used by `db:import`). */
    public function executeScript(string $sql): int
    {
        $count = 0;
        foreach (self::splitStatements($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $this->db->statement($statement);
            $count++;
        }

        return $count;
    }

    /** @return array<int, string> */
    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*(--|#).*$/m', '', $sql) ?? $sql;
        $out = [];
        $buffer = '';
        $inString = false;
        $quote = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                $buffer .= $ch;
                if ($ch === $quote && ($i + 1 >= $len || $sql[$i + 1] !== $quote)) {
                    $inString = false;
                } elseif ($ch === $quote && $i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buffer .= $sql[++$i];
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $out[] = $buffer;
        }

        return $out;
    }
}
