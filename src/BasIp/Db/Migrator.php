<?php

declare(strict_types=1);

namespace BasIp\Db;

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Support\Fs;
use RuntimeException;
use Throwable;

/**
 * Forward-only-ish migration runner with batching, checksums and rollback.
 *
 * State lives in the `migrations` table:
 *   id · migration (unique file name) · batch · checksum · executed_at · duration_ms
 *
 * A changed checksum on an already-applied migration raises a warning — it is
 * the single most common cause of "works on my machine" schema drift.
 */
final class Migrator
{
    private string $table;
    private string $path;

    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ?Logger $logger = null,
    ) {
        $this->table = $config->string('db.migrations_table', 'migrations');
        $this->path  = $config->getPath('database') . '/migrations';
    }

    public function ensureTable(): void
    {
        if ($this->db->tableExists($this->table)) {
            return;
        }
        $this->schema->create($this->table, function (Blueprint $t): void {
            $t->id();
            $t->string('migration', 190)->default('');
            $t->integer('batch')->default(0);
            $t->string('checksum', 64)->nullable();
            $t->integer('duration_ms')->nullable();
            $t->dateTime('executed_at')->nullable();
            $t->unique(['migration']);
        });
    }

    /** @return array<int, string> */
    public function files(): array
    {
        $files = Fs::glob($this->path, '*.php');
        sort($files);

        return $files;
    }

    /** @return array<string, array<string, mixed>> */
    public function status(): array
    {
        $this->ensureTable();
        $applied = [];
        foreach ($this->db->table($this->table)->get() as $row) {
            $applied[(string) $row['migration']] = $row;
        }

        $out = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            $row = $applied[$name] ?? null;
            $out[$name] = [
                'file'      => $name,
                'applied'   => $row !== null,
                'batch'     => $row === null ? null : (int) ($row['batch'] ?? 0),
                'executed_at' => $row['executed_at'] ?? null,
                'checksum_ok' => $row === null ? null : hash_equals((string) ($row['checksum'] ?? ''), $checksum),
                'description' => $this->describe($file),
            ];
        }
        // Migrations present in the DB but no longer on disk.
        foreach ($applied as $name => $row) {
            if (!isset($out[$name])) {
                $out[$name] = [
                    'file' => $name, 'applied' => true, 'batch' => (int) ($row['batch'] ?? 0),
                    'executed_at' => $row['executed_at'] ?? null, 'checksum_ok' => null,
                    'description' => '⚠ файл миграции отсутствует',
                    'orphan' => true,
                ];
            }
        }
        ksort($out);

        return $out;
    }

    public function pending(): array
    {
        $this->ensureTable();
        $applied = $this->db->table($this->table)->pluck('migration');
        $pending = [];
        foreach ($this->files() as $file) {
            if (!in_array(basename($file), $applied, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /** @return array<int, string> executed migration names */
    public function migrate(?int $step = null, bool $force = false): array
    {
        $this->ensureTable();
        $pending = $this->pending();
        if ($step !== null) {
            $pending = array_slice($pending, 0, max(0, $step));
        }
        if ($pending === []) {
            return [];
        }

        $batch = (int) $this->db->table($this->table)->max('batch') + 1;
        $executed = [];

        foreach ($pending as $file) {
            $name = basename($file);
            $started = microtime(true);
            try {
                $migration = $this->resolve($file);
                $this->runMigration($migration, 'up');
            } catch (Throwable $e) {
                $message = sprintf('Миграция %s завершилась ошибкой: %s', $name, $e->getMessage());
                $this->logger?->error($message, ['channel' => 'app', 'file' => $name]);
                if (!$force) {
                    throw new RuntimeException($message, 0, $e);
                }
                $this->logger?->warning('Migration failure ignored (--force)', ['channel' => 'app', 'file' => $name, 'error' => $e->getMessage()]);
            }

            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->db->table($this->table)->insert([
                'migration'   => $name,
                'batch'       => $batch,
                'checksum'    => hash_file('sha256', $file) ?: null,
                'duration_ms' => $duration,
                'executed_at' => $this->clock->format(),
            ]);
            $executed[] = $name;
            $this->logger?->info('Migration applied', ['channel' => 'app', 'file' => $name, 'ms' => $duration]);
        }

        return $executed;
    }

    /** Roll back the last batch (or `$step` batches). */
    public function rollback(int $step = 1): array
    {
        $this->ensureTable();
        $batches = $this->db->table($this->table)->select('batch')->distinct()->orderBy('batch', 'desc')->limit($step)->pluck('batch');
        $rolled = [];
        foreach ($batches as $batch) {
            $rows = $this->db->table($this->table)->where('batch', '=', (int) $batch)->orderBy('id', 'desc')->get();
            foreach ($rows as $row) {
                $name = (string) $row['migration'];
                $file = $this->path . '/' . $name;
                if (!is_file($file)) {
                    $this->logger?->warning('Migration file missing, skipping rollback', ['channel' => 'app', 'file' => $name]);
                    $this->db->table($this->table)->where('migration', '=', $name)->delete();
                    continue;
                }
                $migration = $this->resolve($file);
                $this->runMigration($migration, 'down');
                $this->db->table($this->table)->where('migration', '=', $name)->delete();
                $rolled[] = $name;
            }
        }

        return $rolled;
    }

    /** Drop everything and re-run all migrations. Destructive. */
    public function fresh(): array
    {
        $this->rollback(9999);
        $this->db->statement('DROP TABLE IF EXISTS ' . $this->db->quoteIdentifier($this->db->prefixTable($this->table)));

        return $this->migrate();
    }

    public function checksumWarnings(): array
    {
        $out = [];
        foreach ($this->status() as $name => $info) {
            if ($info['applied'] && $info['checksum_ok'] === false) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Run one direction of a migration, wrapped in a transaction only when the
     * engine can actually roll DDL back.
     */
    private function runMigration(Migration $migration, string $direction): void
    {
        $run = function () use ($migration, $direction): void {
            $migration->{$direction}($this->schema, $this->db);
        };

        if ($this->db->supportsTransactionalDdl()) {
            $this->db->transaction($run);

            return;
        }
        $run();
    }

    private function resolve(string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException('Migration file must return an instance of ' . Migration::class . ': ' . $file);
        }

        return $migration;
    }

    private function describe(string $file): string
    {
        try {
            return $this->resolve($file)->describe();
        } catch (Throwable) {
            return '';
        }
    }
}
