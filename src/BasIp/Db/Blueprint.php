<?php

declare(strict_types=1);

namespace BasIp\Db;

use BasIp\Core\Logger;
use Closure;
use RuntimeException;

/**
 * Column/index definition collector for {@see Schema}.
 *
 * @method ColumnDefinition id(string $name = 'id')
 */
final class Blueprint
{
    /** @var array<int, ColumnDefinition> */
    private array $columns = [];

    /** @var array<int, array<string, mixed>> */
    private array $indexes = [];

    /** @var array<int, string> */
    private array $postCreate = [];

    /** @var array<int, ColumnDefinition> */
    private array $dropped = [];

    /** @var array<int, array{from: string, to: ColumnDefinition}> */
    private array $changes = [];

    public function __construct(private readonly Database $db, private readonly string $table)
    {
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn($name, 'id')->primary();
    }

    public function bigIncrements(string $name): ColumnDefinition
    {
        return $this->id($name);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, 'string', ['length' => $length]);
    }

    public function char(string $name, int $length = 36): ColumnDefinition
    {
        return $this->addColumn($name, 'char', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'text');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'text');
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'boolean');
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'tinyint');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'smallint');
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer');
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer', ['unsigned' => true]);
    }

    /**
     * BIGINT column.
     *
     * Unsigned by default on MySQL/MariaDB so it matches `id()` exactly —
     * InnoDB rejects a foreign key whose signedness differs from the referenced
     * column (errno 150). The flag is ignored on PostgreSQL and SQLite.
     */
    public function bigInteger(string $name, bool $unsigned = true): ColumnDefinition
    {
        return $this->addColumn($name, 'bigint', ['unsigned' => $unsigned]);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name, true);
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->bigInteger($name, true);
    }

    public function decimal(string $name, int $precision = 12, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'float');
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'date');
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'datetime');
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'datetime');
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'json');
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'binary');
    }

    /** Portable enum: VARCHAR + CHECK constraint. */
    public function enum(string $name, array $values, int $length = 32): ColumnDefinition
    {
        $column = $this->addColumn($name, 'enum', ['length' => $length, 'values' => array_values(array_map('strval', $values))]);

        return $column;
    }

    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->dateTime($name)->nullable();
    }

    public function comment(string $name, string $comment): ColumnDefinition
    {
        $column = $this->addColumn($name, 'text')->nullable();
        $column->comment($comment);

        return $column;
    }

    /** @param array<int, string> $columns */
    public function index(array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['type' => 'index', 'columns' => $columns, 'name' => $name];

        return $this;
    }

    /** @param array<int, string> $columns */
    public function unique(array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => $columns, 'name' => $name];

        return $this;
    }

    /** @param array<int, string> $columns */
    public function fulltext(array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['type' => 'fulltext', 'columns' => $columns, 'name' => $name];

        return $this;
    }

    public function foreign(string $column, string $references, string $on, string $onDelete = 'cascade', ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'foreign', 'columns' => [$column], 'references' => $references,
            'on' => $on, 'onDelete' => $onDelete, 'name' => $name,
        ];

        return $this;
    }

    public function dropColumn(string $name): self
    {
        $this->dropped[] = new ColumnDefinition($this->db, $name, 'string');

        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->changes[] = ['from' => $from, 'to' => new ColumnDefinition($this->db, $to, 'string')];

        return $this;
    }

    /** @return array<int, string> */
    public function postCreate(): array
    {
        return $this->postCreate;
    }

    private function addColumn(string $name, string $type, array $options = []): ColumnDefinition
    {
        $column = new ColumnDefinition($this->db, $name, $type, $options);
        $this->columns[] = $column;

        return $column;
    }

    public function toCreateSql(): string
    {
        if ($this->columns === []) {
            throw new RuntimeException('Cannot create table "' . $this->table . '" without columns.');
        }

        $definitions = [];
        $inlineFk = [];
        $checks = [];
        foreach ($this->columns as $column) {
            $definitions[] = $column->toSql();
            $check = $column->checkSql();
            if ($check !== null) {
                $checks[] = $check;
            }
        }

        $lines = array_merge($definitions, $checks);

        foreach ($this->indexes as $index) {
            if ($index['type'] === 'foreign') {
                $inlineFk[] = $this->foreignKeySql($index);
                continue;
            }
            if ($index['type'] === 'unique') {
                $lines[] = 'CONSTRAINT ' . $this->db->quoteIdentifier($this->indexName($index))
                    . ' UNIQUE (' . $this->columnList($index['columns']) . ')';
                continue;
            }
            if ($index['type'] === 'index' && $this->db->isPgsql() === false && $this->db->isSqlite() === false) {
                $lines[] = 'KEY ' . $this->db->quoteIdentifier($this->indexName($index)) . ' (' . $this->columnList($index['columns']) . ')';
                continue;
            }
            // PostgreSQL / SQLite: plain indexes are separate statements.
            $this->postCreate[] = $this->createIndexSql($index);
        }

        $lines = array_merge($lines, $inlineFk);

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $this->db->quoteIdentifier($this->db->prefixTable($this->table)),
            implode(', ', $lines)
        );

        if ($this->db->isMySql()) {
            $settings = $this->db->settings();
            $sql .= sprintf(
                ' ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s',
                (string) ($settings['engine'] ?? 'InnoDB'),
                (string) ($settings['charset'] ?? 'utf8mb4'),
                (string) ($settings['collation'] ?? 'utf8mb4_unicode_ci')
            );
        }

        return $sql;
    }

    /** @return array<int, string> */
    public function toAlterSql(): array
    {
        $statements = [];
        $table = $this->db->quoteIdentifier($this->db->prefixTable($this->table));

        foreach ($this->dropped as $column) {
            $statements[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $this->db->quoteIdentifier($column->name);
        }
        foreach ($this->changes as $change) {
            if ($this->db->isMySql()) {
                $statements[] = sprintf('ALTER TABLE %s CHANGE %s %s VARCHAR(255)', $table, $this->db->quoteIdentifier($change['from']), $this->db->quoteIdentifier($change['to']->name));
            } else {
                $statements[] = sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $this->db->quoteIdentifier($change['from']), $this->db->quoteIdentifier($change['to']->name));
            }
        }
        foreach ($this->columns as $column) {
            $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $column->toSql());
            $check = $column->checkSql();
            if ($check !== null) {
                $statements[] = 'ALTER TABLE ' . $table . ' ADD ' . $check;
            }
        }
        foreach ($this->indexes as $index) {
            if ($index['type'] === 'foreign') {
                if ($this->db->isSqlite()) {
                    continue;      // SQLite cannot add foreign keys after the fact.
                }
                $constraint = $this->indexName($index);
                $statements[] = sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s %s',
                    $table,
                    $this->db->quoteIdentifier($constraint),
                    $this->foreignKeySql($index, false)
                );
                continue;
            }
            if ($index['type'] === 'unique' && !$this->db->isMySql()) {
                $statements[] = $this->createIndexSql($index, true);
                continue;
            }
            if ($index['type'] === 'unique' && $this->db->isMySql()) {
                $statements[] = sprintf('ALTER TABLE %s ADD UNIQUE KEY %s (%s)', $table, $this->db->quoteIdentifier($this->indexName($index)), $this->columnList($index['columns']));
                continue;
            }
            $statements[] = $this->createIndexSql($index);
        }

        return $statements;
    }

    /** @param array<string, mixed> $index */
    private function foreignKeySql(array $index, bool $withPrefix = true): string
    {
        $name = $this->indexName($index);
        $onTable = $this->db->quoteIdentifier($this->db->prefixTable((string) $index['on']));
        $onDelete = strtoupper((string) ($index['onDelete'] ?? 'cascade'));
        $sql = sprintf(
            'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
            $this->columnList($index['columns']),
            $onTable,
            $this->db->quoteIdentifier((string) $index['references']),
            in_array($onDelete, ['CASCADE', 'SET NULL', 'NO ACTION', 'RESTRICT', 'SET DEFAULT'], true) ? $onDelete : 'CASCADE'
        );

        return $withPrefix ? 'CONSTRAINT ' . $this->db->quoteIdentifier($name) . ' ' . $sql : $sql;
    }

    /** @param array<string, mixed> $index */
    private function createIndexSql(array $index, bool $unique = false): string
    {
        $unique = $unique || ($index['type'] ?? '') === 'unique';
        $type = '';
        if (($index['type'] ?? '') === 'fulltext' && $this->db->isMySql()) {
            $type = 'FULLTEXT ';
        }

        return sprintf(
            'CREATE %s%sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $type,
            $this->db->quoteIdentifier($this->indexName($index)),
            $this->db->quoteIdentifier($this->db->prefixTable($this->table)),
            $this->columnList($index['columns'])
        );
    }

    /** @param array<string, mixed> $index */
    private function indexName(array $index): string
    {
        if (!empty($index['name'])) {
            return (string) $index['name'];
        }
        $prefix = $this->db->prefixTable($this->table);
        $suffix = match ($index['type']) {
            'unique'   => 'unique',
            'foreign'  => 'fk',
            'fulltext' => 'ft',
            default    => 'index',
        };

        return substr($prefix . '_' . implode('_', (array) $index['columns']) . '_' . $suffix, 0, 64);
    }

    /** @param array<int, string> $columns */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(fn (string $c): string => $this->db->quoteIdentifier($c), $columns));
    }
}
