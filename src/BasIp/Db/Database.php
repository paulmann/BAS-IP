<?php

declare(strict_types=1);

namespace BasIp\Db;

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Support\Fs;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO connection manager + portable SQL dialect helper.
 *
 * Supported drivers: `mysql` (MySQL 8 / MariaDB 10.6+, the default),
 * `pgsql` (PostgreSQL 14+) and `sqlite` (demos, CI, the test-suite).
 *
 * Design rules that keep one code base working on all three:
 *  * positional `?` placeholders only — never string interpolation of values
 *  * booleans are stored as 0/1 integers
 *  * timestamps are plain `Y-m-d H:i:s` strings in the application timezone
 *  * identifiers are quoted per driver (`\`col\`` vs `"col"`)
 *  * all dialect differences live in this class (see `groupConcat()`,
 *    `upsertSql()`, `ilike()`, `random()`, `nowSql()`, `jsonType()` …)
 */
final class Database
{
    private ?PDO $pdo = null;
    private string $driver;
    private string $prefix;
    private int $transactionDepth = 0;
    private int $queryCount = 0;
    private float $queryTimeMs = 0.0;
    /** @var array<int, array{sql: string, ms: float, rows: int}> */
    private array $queryLog = [];
    private bool $loggingQueries = false;
    private string $connection;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ?Logger $logger = null,
        ?string $connection = null,
    ) {
        $this->driver = strtolower((string) ($connection
            ? $config->string('db.connections.' . $connection . '.driver', $connection)
            : $config->string('db.connections.' . $config->string('db.default', 'mysql') . '.driver', 'mysql')));
        $this->connection = $connection ?? $config->string('db.default', 'mysql');
        $this->prefix     = (string) $config->string('db.prefix', '');
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * Re-read the connection parameters from the config and drop any
     * established PDO connection.
     *
     * The web installer overrides `db.*` *after* the container has already
     * built this object (middleware resolves the guard first), and long-running
     * workers may need to reconnect after a config change — this is the safe
     * way to pick up the new settings without rebuilding every service that
     * holds a reference.
     */
    public function reload(?string $connection = null): void
    {
        $this->pdo        = null;
        $this->connection = $connection ?? $this->config->string('db.default', 'mysql');
        $this->driver     = strtolower((string) $this->config->string('db.connections.' . $this->connection . '.driver', $this->connection));
        $this->prefix     = (string) $this->config->string('db.prefix', '');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    public function isMySql(): bool   { return in_array($this->driver, ['mysql', 'mariadb'], true); }
    public function isPgsql(): bool   { return $this->driver === 'pgsql'; }
    public function isSqlite(): bool  { return $this->driver === 'sqlite'; }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $settings = $this->config->section('db.connections.' . $this->connection);
        if ($settings === []) {
            throw new RuntimeException('Unknown database connection: ' . $this->connection);
        }
        $settings['driver'] = strtolower((string) ($settings['driver'] ?? $this->driver));

        return $settings;
    }

    private function connect(): PDO
    {
        $settings = $this->settings();
        $driver   = (string) $settings['driver'];

        [$dsn, $username, $password] = $this->buildDsn($driver, $settings);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ] + (array) ($settings['options'] ?? []);

        if (!empty($settings['persistent'])) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        if ($this->isMySql()) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            $this->logger?->error('Database connection failed', [
                'driver' => $driver, 'error' => $e->getMessage(), 'channel' => 'app',
            ]);

            throw new RuntimeException(
                sprintf('Не удалось подключиться к базе данных (%s): %s', $driver, self::sanitizeDsn($dsn) . ' — ' . $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }

        $this->configure($pdo, $driver, $settings);

        return $pdo;
    }

    /** @param array<string, mixed> $settings @return array{0: string, 1: ?string, 2: ?string} */
    private function buildDsn(string $driver, array $settings): array
    {
        $username = isset($settings['username']) ? (string) $settings['username'] : null;
        $password = isset($settings['password']) ? (string) $settings['password'] : null;

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $charset = (string) ($settings['charset'] ?? 'utf8mb4');
                if (!empty($settings['unix_socket'])) {
                    $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $settings['unix_socket'], $settings['database'], $charset);
                } else {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                        $settings['host'] ?? '127.0.0.1',
                        (int) ($settings['port'] ?? 3306),
                        $settings['database'] ?? '',
                        $charset
                    );
                }

                return [$dsn, $username, $password];

            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $settings['host'] ?? '127.0.0.1',
                    (int) ($settings['port'] ?? 5432),
                    $settings['database'] ?? ''
                );
                if (!empty($settings['options'])) {
                    // PDO pgsql accepts `options=-csearch_path=…` only via the DSN on newer versions;
                    // we set the search_path explicitly after connecting instead.
                }

                return [$dsn, $username, $password];

            case 'sqlite':
            case 'sqlite3':
                $file = (string) ($settings['database'] ?? ':memory:');
                if ($file !== ':memory:') {
                    Fs::ensureDir(dirname($file));
                    if (!file_exists($file)) {
                        touch($file);
                        @chmod($file, 0640);
                    }
                }

                return ['sqlite:' . $file, null, null];

            default:
                throw new RuntimeException('Unsupported database driver: ' . $driver);
        }
    }

    /** @param array<string, mixed> $settings */
    private function configure(PDO $pdo, string $driver, array $settings): void
    {
        $timezone = (string) $this->config->string('app.timezone', 'UTC');
        $offset   = $this->clock->datetime()->format('P');

        if ($this->isMySql()) {
            $collation = (string) ($settings['collation'] ?? 'utf8mb4_unicode_ci');
            $init = sprintf(
                "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', "
                . "time_zone = '%s', NAMES %s COLLATE %s",
                addslashes($offset),
                (string) ($settings['charset'] ?? 'utf8mb4'),
                addslashes($collation)
            );
            $pdo->exec($init);
        } elseif ($this->isPgsql()) {
            $schema = (string) ($settings['schema'] ?? 'public');
            $pdo->exec(sprintf("SET search_path TO %s", $this->quoteIdentifier($schema)));
            // PostgreSQL accepts a named zone ('Europe/Moscow') or an ISO offset
            // with the colon ('+03:00') — but NOT the MySQL-style '+0300'.
            $pgZone = str_contains($timezone, '/') ? $timezone : $offset;
            $pdo->exec(sprintf("SET TIME ZONE '%s'", addslashes($pgZone)));
        } else {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = ' . (int) ($settings['busy_timeout'] ?? 5000));
        }
        unset($timezone);
    }

    /* ─────────────────────────── query API ─────────────────────────── */

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function query(): Query
    {
        return new Query($this, '');
    }

    /** @param array<int|string, mixed> $bindings */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $st) => $st->fetchAll());
    }

    /** @param array<int|string, mixed> $bindings */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->run($sql, $bindings, static fn (PDOStatement $st) => $st->fetchAll());

        return $rows[0] ?? null;
    }

    /** @param array<int|string, mixed> $bindings */
    public function scalar(string $sql, array $bindings = [], mixed $default = null): mixed
    {
        $row = $this->selectOne($sql, $bindings);
        if ($row === null) {
            return $default;
        }
        $value = reset($row);

        return $value === false ? $default : $value;
    }

    /** @param array<int|string, mixed> $bindings */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $st) => $st->rowCount());
    }

    /** @param array<int|string, mixed> $bindings */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->run($sql, $bindings, static fn (PDOStatement $st) => $st->rowCount());
        $id = $this->pdo()->lastInsertId();
        if ($this->isPgsql()) {
            // PDO returns the sequence value only when the column is known; be explicit.
            return $id !== '' && $id !== '0' ? $id : (string) $this->scalar('SELECT lastval()', [], '0');
        }

        return (string) $id;
    }

    /** @param array<int|string, mixed> $bindings */
    public function run(string $sql, array $bindings, callable $callback): mixed
    {
        $sql = $this->applyPrefix($sql);
        $started = microtime(true);
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach ($bindings as $key => $value) {
                $param = is_int($key) ? $key + 1 : $key;
                $type = match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_INT,
                    is_null($value) => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                };
                $statement->bindValue($param, is_bool($value) ? (int) $value : $value, $type);
            }
            $statement->execute();
            $result = $callback($statement);
        } catch (PDOException $e) {
            $this->logger?->error('SQL error', [
                'sql'     => $sql,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
                'bindings' => count($bindings),
                'channel' => 'app',
            ]);

            throw $e;
        } finally {
            $ms = (microtime(true) - $started) * 1000;
            $this->queryCount++;
            $this->queryTimeMs += $ms;
            if ($this->loggingQueries) {
                $this->queryLog[] = ['sql' => $sql, 'ms' => round($ms, 2), 'rows' => is_array($result ?? null) ? count($result) : 0];
                if (count($this->queryLog) > 500) {
                    array_shift($this->queryLog);
                }
            }
        }

        return $result;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $this->transactionDepth++;

        if ($this->transactionDepth === 1) {
            $pdo->beginTransaction();
        } else {
            // Nested "transactions" become savepoints where supported.
            if (!$this->isSqlite()) {
                try {
                    $pdo->exec('SAVEPOINT trans' . $this->transactionDepth);
                } catch (Throwable) {
                    // Not fatal — the outer transaction still protects us.
                }
            }
        }

        try {
            $result = $callback($this);
            if ($this->transactionDepth === 1) {
                // DDL implicitly commits on MySQL/MariaDB, so the transaction may
                // already be gone by the time we get here.
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } elseif (!$this->isSqlite()) {
                try {
                    $pdo->exec('RELEASE SAVEPOINT trans' . $this->transactionDepth);
                } catch (Throwable) {
                }
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->transactionDepth === 1) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif (!$this->isSqlite()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionDepth);
                } catch (Throwable) {
                }
            }
            throw $e;
        } finally {
            $this->transactionDepth = max(0, $this->transactionDepth - 1);
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * MySQL/MariaDB perform an implicit COMMIT before and after every DDL
     * statement, so migrations there are not atomic. PostgreSQL and SQLite are.
     */
    public function supportsTransactionalDdl(): bool
    {
        return !$this->isMySql();
    }

    /* ─────────────────────────── dialect helpers ─────────────────────────── */

    public function tablePrefix(): string
    {
        return $this->prefix;
    }

    public function prefixTable(string $table): string
    {
        if ($this->prefix === '' || str_starts_with($table, $this->prefix)) {
            return $table;
        }

        return $this->prefix . $table;
    }

    /** Replace `{table}` markers with the prefixed + quoted identifier. */
    public function applyPrefix(string $sql): string
    {
        if ($this->prefix === '' || !str_contains($sql, '{')) {
            return $sql;
        }

        return (string) preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', fn (array $m): string => $this->quoteIdentifier($this->prefix . $m[1]), $sql);
    }

    public function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        $quoted = array_map(function (string $part): string {
            if ($part === '*') {
                return '*';
            }
            $char = $this->isMySql() ? '`' : '"';

            return $char . str_replace($char, $char . $char, $part) . $char;
        }, $parts);

        return implode('.', $quoted);
    }

    public function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    /** Portable case-insensitive LIKE operator. */
    public function ilikeOp(): string
    {
        return $this->isPgsql() ? 'ILIKE' : 'LIKE';
    }

    /** Portable string aggregation: `GROUP_CONCAT(x SEPARATOR ', ')` / `STRING_AGG(x, ', ')`. */
    public function groupConcat(string $expression, string $separator = ', ', ?string $orderBy = null): string
    {
        if ($this->isPgsql()) {
            $order = $orderBy !== null ? ' ORDER BY ' . $orderBy : '';

            return sprintf('STRING_AGG(%s::text, %s%s)', $expression, $this->quote($separator), $order);
        }
        if ($this->isSqlite()) {
            return sprintf('GROUP_CONCAT(%s, %s)', $expression, $this->quote($separator));
        }
        $order = $orderBy !== null ? ' ORDER BY ' . $orderBy : '';

        return sprintf('GROUP_CONCAT(%s%s SEPARATOR %s)', $expression, $order, $this->quote($separator));
    }

    public function random(): string
    {
        return match (true) {
            $this->isMySql()  => 'RAND()',
            $this->isPgsql()  => 'RANDOM()',
            default           => 'RANDOM()',
        };
    }

    public function nowSql(): string
    {
        return match (true) {
            $this->isMySql()  => 'NOW()',
            $this->isPgsql()  => 'LOCALTIMESTAMP',
            default           => 'CURRENT_TIMESTAMP',
        };
    }

    public function jsonType(): string
    {
        return match (true) {
            $this->isMySql()  => 'JSON',
            $this->isPgsql()  => 'JSONB',
            default           => 'TEXT',
        };
    }

    public function boolType(): string
    {
        return $this->isPgsql() ? 'SMALLINT' : 'TINYINT';
    }

    public function textType(): string
    {
        return match (true) {
            $this->isMySql()  => 'LONGTEXT',
            $this->isPgsql()  => 'TEXT',
            default           => 'TEXT',
        };
    }

    public function dateTimeType(): string
    {
        return match (true) {
            $this->isMySql()  => 'DATETIME',
            $this->isPgsql()  => 'TIMESTAMP',
            default           => 'TEXT',
        };
    }

    public function uuidType(): string
    {
        return $this->isPgsql() ? 'UUID' : 'CHAR(36)';
    }

    /** Portable `LIMIT … OFFSET …` — identical on all three drivers. */
    public function limitOffset(?int $limit, int $offset): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }
        if ($limit === null) {
            // PostgreSQL refuses a bare OFFSET without LIMIT.
            return $this->isPgsql() ? ' LIMIT ALL OFFSET ' . $offset : ' LIMIT -1 OFFSET ' . $offset;
        }

        return sprintf(' LIMIT %d OFFSET %d', max(0, $limit), max(0, $offset));
    }

    /** Current application timestamp as a DB-ready string. */
    public function now(?string $format = 'Y-m-d H:i:s'): string
    {
        return $this->clock->format($format);
    }

    /* ─────────────────────────── diagnostics ─────────────────────────── */

    public function ping(): bool
    {
        try {
            $this->pdo()->query($this->isPgsql() ? 'SELECT 1' : 'SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function serverInfo(): array
    {
        $info = [
            'driver'      => $this->driver,
            'connection'  => $this->connection,
            'pdo_driver'  => (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server'      => '',
            'version'     => '',
            'client'      => '',
            'queries'     => $this->queryCount,
            'query_ms'    => round($this->queryTimeMs, 2),
        ];
        try {
            $info['version'] = (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            $info['client']  = (string) $this->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION);
        } catch (Throwable) {
        }
        try {
            $info['server'] = match (true) {
                $this->isPgsql() => (string) $this->scalar('SELECT current_database()', [], ''),
                $this->isMySql() => (string) $this->scalar('SELECT DATABASE()', [], ''),
                // SQLite has no "current database" function — report the file.
                default          => basename((string) ($this->settings()['database'] ?? 'sqlite')),
            };
        } catch (Throwable) {
            $info['server'] = '';
        }

        return $info;
    }

    public function enableQueryLog(): void
    {
        $this->loggingQueries = true;
        $this->queryLog = [];
    }

    /** @return array<int, array{sql: string, ms: float, rows: int}> */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function stats(): array
    {
        return ['queries' => $this->queryCount, 'ms' => round($this->queryTimeMs, 2)];
    }

    public function disconnect(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public static function sanitizeDsn(string $dsn): string
    {
        return (string) preg_replace('/(password|pwd)=[^;]+/i', '$1=***', $dsn);
    }

    /** Table list — used by the installer and the health check. */
    public function tables(): array
    {
        $prefix = $this->prefix;
        switch (true) {
            case $this->isMySql():
                $rows = $this->select('SHOW FULL TABLES');
                $names = array_map('strval', array_map(static fn ($r) => reset($r) ?: '', $rows));
                break;
            case $this->isPgsql():
                $schema = (string) ($this->settings()['schema'] ?? 'public');
                $rows = $this->select('SELECT tablename FROM pg_tables WHERE schemaname = ?', [$schema]);
                $names = array_column($rows, 'tablename');
                break;
            default:
                $rows = $this->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
                $names = array_column($rows, 'name');
        }
        sort($names);

        return $names;
    }

    public function tableExists(string $table): bool
    {
        $table = $this->prefixTable($table);

        return in_array($table, $this->tables(), true);
    }

    /** @return array<int, string> */
    public function columns(string $table): array
    {
        $table = $this->prefixTable($table);
        switch (true) {
            case $this->isMySql():
                $rows = $this->select('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table));

                return array_column($rows, 'Field');
            case $this->isPgsql():
                $schema = (string) ($this->settings()['schema'] ?? 'public');
                $rows = $this->select(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                    [$schema, $table]
                );

                return array_column($rows, 'column_name');
            default:
                $rows = $this->select('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');

                return array_column($rows, 'name');
        }
    }

    public function sizeBytes(): int
    {
        try {
            switch (true) {
                case $this->isMySql():
                    $db = (string) $this->scalar('SELECT DATABASE()', [], '');

                    return (int) $this->scalar(
                        'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?',
                        [$db],
                        0
                    );
                case $this->isPgsql():
                    $db = (string) $this->scalar('SELECT current_database()', [], '');

                    return (int) $this->scalar('SELECT pg_database_size(?)', [$db], 0);
                default:
                    $file = (string) ($this->settings()['database'] ?? '');

                    return is_file($file) ? (int) filesize($file) : 0;
            }
        } catch (Throwable) {
            return 0;
        }
    }
}
