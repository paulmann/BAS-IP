<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Db\Schema;
use BasIp\Support\Fs;
use BasIp\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Backup & restore.
 *
 * Two dump strategies, picked automatically:
 *   1. native tools (`mysqldump` / `pg_dump`) when they are on PATH — fastest
 *      and most faithful;
 *   2. a pure-PHP portable dump (SELECT + INSERT batches) — works everywhere,
 *      including shared hosting with no shell access, and is dialect-neutral
 *      so a MySQL backup can be restored into PostgreSQL.
 *
 * Archives are ZIP; optional AES-256-GCM encryption uses the application key.
 */
final class BackupService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly Crypto $crypto,
        private readonly AuditService $audit,
    ) {
    }

    /** Tables that must never be dumped (transient state). */
    private const EXCLUDE = ['sessions_meta'];

    /**
     * @return array{ok: bool, file?: string, path?: string, bytes?: int, tables?: int, rows?: int, method?: string, error?: string}
     */
    public function create(string $type = 'db', string $note = '', ?User $actor = null, ?Request $request = null): array
    {
        $dir = $this->path();
        Fs::ensureDir($dir);

        $stamp = $this->clock->format('Ymd-His');
        $driver = $this->db->driver();
        $filename = sprintf('bas-ip-%s-%s-%s.zip', $type, $driver, $stamp);
        $path = $dir . '/' . $filename;

        try {
            $tmp = $this->config->getPath('storage') . '/app/.backup-' . bin2hex(random_bytes(4));
            Fs::ensureDir($tmp, 0700);

            $result = $type === 'full'
                ? $this->dumpFull($tmp)
                : $this->dumpDatabase($tmp);

            $this->makeArchive($tmp, $path);
            Fs::deleteDir($tmp);

            if ($this->config->bool('backup.encrypt', false) && $this->crypto->available()) {
                $path = $this->encryptFile($path);
                $filename = basename($path);
            }

            $bytes = Fs::size($path);
            $id = $this->db->table('backups')->insertGetId([
                'filename' => $filename,
                'path' => $path,
                'type' => $type,
                'size_bytes' => $bytes,
                'checksum' => hash_file('sha256', $path) ?: null,
                'driver' => $driver,
                'encrypted' => $this->config->bool('backup.encrypt', false) ? 1 : 0,
                'status' => 'done',
                'note' => mb_substr($note, 0, 2000) ?: null,
                'meta' => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_by' => $actor?->id,
                'created_at' => $this->clock->format(),
            ]);

            $this->rotate();
            $this->audit->info('backup.create', $actor, 'backup', (string) $id, $filename, $request, $result);

            return ['ok' => true, 'file' => $filename, 'path' => $path, 'bytes' => $bytes, 'id' => $id] + $result;
        } catch (Throwable $e) {
            $this->logger->channel('app')->error('Backup failed', ['error' => $e->getMessage()]);
            try {
                $this->db->table('backups')->insert([
                    'filename' => $filename, 'path' => '', 'type' => $type, 'status' => 'failed',
                    'note' => mb_substr($e->getMessage(), 0, 2000), 'driver' => $driver,
                    'created_by' => $actor?->id, 'created_at' => $this->clock->format(),
                ]);
            } catch (Throwable) {
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{tables: int, rows: int, method: string} */
    private function dumpDatabase(string $dir): array
    {
        // 1) try the native dumper
        $native = $this->tryNativeDump($dir);
        if ($native !== null) {
            return $native;
        }

        // 2) portable PHP dump
        $tables = $this->db->tables();
        $rows = 0;
        $sql = [];
        $sql[] = '-- BAS-IP portable SQL dump';
        $sql[] = '-- generated: ' . $this->clock->format(DATE_ATOM);
        $sql[] = '-- driver: ' . $this->db->driver() . ' / ' . ($this->db->serverInfo()['version'] ?? '?');
        $sql[] = '';

        foreach ($tables as $table) {
            if (in_array($table, self::EXCLUDE, true)) {
                continue;
            }
            $sql[] = '-- ── ' . $table . ' ──';
            $columns = $this->db->columns($table);
            if ($columns === []) {
                continue;
            }
            $quoted = implode(', ', array_map(fn (string $c): string => $this->db->quoteIdentifier($c), $columns));
            $count = 0;

            $handle = $this->db->pdo()->query('SELECT * FROM ' . $this->db->quoteIdentifier($table));
            if ($handle === false) {
                continue;
            }
            while (($row = $handle->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $values[] = $this->sqlValue($value);
                }
                $sql[] = sprintf('INSERT INTO %s (%s) VALUES (%s);', $this->db->quoteIdentifier($table), $quoted, implode(', ', $values));
                $count++;
                $rows++;
                if (count($sql) > 5000) {
                    $this->flushSql($dir, $sql);
                    $sql = [];
                }
            }
            $sql[] = '-- ' . $table . ': ' . $count . ' rows';
        }

        $this->flushSql($dir, $sql);

        return ['tables' => count($tables), 'rows' => $rows, 'method' => 'php'];
    }

    /** @return array{tables: int, rows: int, method: string}|null */
    private function tryNativeDump(string $dir): ?array
    {
        $settings = $this->db->settings();
        $driver = $this->db->driver();

        if ($driver === 'mysql') {
            $binary = $this->findBinary(['mysqldump', 'mariadb-dump']);
            if ($binary === null) {
                return null;
            }
            $file = $dir . '/database.sql';
            $cmd = sprintf(
                '%s --host=%s --port=%d --user=%s --password=%s --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 %s > %s 2>%s',
                escapeshellcmd($binary),
                escapeshellarg((string) ($settings['host'] ?? '127.0.0.1')),
                (int) ($settings['port'] ?? 3306),
                escapeshellarg((string) ($settings['username'] ?? '')),
                escapeshellarg((string) ($settings['password'] ?? '')),
                escapeshellarg((string) ($settings['database'] ?? '')),
                escapeshellarg($file),
                escapeshellarg($dir . '/dump.err')
            );
            exec($cmd, $output, $code);
            if ($code !== 0 || !is_file($file) || Fs::size($file) === 0) {
                $this->logger->channel('app')->warning('mysqldump failed, falling back to the PHP dumper', [
                    'code' => $code, 'err' => substr(Fs::get($dir . '/dump.err'), 0, 300),
                ]);
                @unlink($file);

                return null;
            }

            return ['tables' => count($this->db->tables()), 'rows' => null, 'method' => 'mysqldump'];
        }

        if ($driver === 'pgsql') {
            $binary = $this->findBinary(['pg_dump']);
            if ($binary === null) {
                return null;
            }
            $file = $dir . '/database.sql';
            $env = sprintf(
                'PGPASSWORD=%s PGHOST=%s PGPORT=%d PGUSER=%s',
                escapeshellarg((string) ($settings['password'] ?? '')),
                escapeshellarg((string) ($settings['host'] ?? '127.0.0.1')),
                (int) ($settings['port'] ?? 5432),
                escapeshellarg((string) ($settings['username'] ?? ''))
            );
            $cmd = sprintf('%s %s --no-owner --no-privileges %s > %s 2>%s',
                $env, escapeshellcmd($binary), escapeshellarg((string) ($settings['database'] ?? '')),
                escapeshellarg($file), escapeshellarg($dir . '/dump.err'));
            exec($cmd, $output, $code);
            if ($code !== 0 || !is_file($file) || Fs::size($file) === 0) {
                @unlink($file);

                return null;
            }

            return ['tables' => count($this->db->tables()), 'rows' => null, 'method' => 'pg_dump'];
        }

        if ($driver === 'sqlite') {
            $source = (string) ($settings['database'] ?? '');
            if (is_file($source)) {
                copy($source, $dir . '/database.sqlite');

                return ['tables' => count($this->db->tables()), 'rows' => null, 'method' => 'file-copy'];
            }
        }

        return null;
    }

    /** @return array{tables: int, rows: int, method: string} */
    private function dumpFull(string $dir): array
    {
        $result = $this->dumpDatabase($dir);

        // Configuration (local file, git-ignored) — the single most painful thing to lose.
        foreach (Config::LOCAL_FILES as $relative) {
            $file = $this->config->root() . '/' . $relative;
            if (is_file($file)) {
                Fs::ensureDir(dirname($dir . '/config/' . $relative));
                copy($file, $dir . '/config/' . $relative);
            }
        }
        if (is_file($this->config->root() . '/.env')) {
            Fs::ensureDir($dir . '/config');
            copy($this->config->root() . '/.env', $dir . '/config/.env');
        }
        if (is_file($this->config->getPath('storage') . '/app/.secrets.php')) {
            Fs::ensureDir($dir . '/secrets');
            copy($this->config->getPath('storage') . '/app/.secrets.php', $dir . '/secrets/.secrets.php');
        }

        if ($this->config->bool('backup.include_storage', false)) {
            Fs::copyDir($this->config->getPath('storage') . '/app/uploads', $dir . '/storage/uploads');
        }

        Fs::put($dir . '/MANIFEST.json', (string) json_encode([
            'app' => 'BAS-IP Gate Manager',
            'version' => $this->config->string('app.version'),
            'created_at' => $this->clock->format(DATE_ATOM),
            'php' => PHP_VERSION,
            'driver' => $this->db->driver(),
            'server' => $this->db->serverInfo(),
            'tables' => $this->db->tables(),
            'result' => $result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0600);

        return $result + ['full' => true];
    }

    private function makeArchive(string $dir, string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('Расширение PHP `zip` не установлено — резервная копия невозможна.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать архив: ' . $path);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $zip->addFile($item->getPathname(), $relative);
        }
        $zip->close();
    }

    private function encryptFile(string $path): string
    {
        $encrypted = $path . '.enc';
        $plain = (string) file_get_contents($path);
        Fs::put($encrypted, "BASIPENC1\n" . $this->crypto->encrypt($plain, 'backup'), 0600);
        @unlink($path);

        return $encrypted;
    }

    private function decryptFile(string $path): string
    {
        $raw = Fs::get($path);
        if (!str_starts_with($raw, "BASIPENC1\n")) {
            return $raw;
        }
        $plain = $this->crypto->decrypt(substr($raw, 10), 'backup');
        if ($plain === null) {
            throw new \RuntimeException('Не удалось расшифровать архив — проверьте app.key.');
        }

        return $plain;
    }

    /** @param array<int, string> $sql */
    private function flushSql(string $dir, array $sql): void
    {
        if ($sql === []) {
            return;
        }
        $file = $dir . '/database.sql';
        $existing = is_file($file) ? (string) file_get_contents($file) : '';
        Fs::put($file, $existing . implode("\n", $sql) . "\n", 0600);
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $this->db->quote((string) $value);
    }

    /** @param array<int, string> $candidates */
    private function findBinary(array $candidates): ?string
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        foreach ($candidates as $binary) {
            $path = trim((string) @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /* ─────────────────────────── listing / restore ─────────────────────────── */

    /** @return array<int, array<string, mixed>> */
    public function list(?int $limit = null): array
    {
        $q = $this->db->table('backups')->orderBy('id', 'desc');
        if ($limit !== null) {
            $q = $q->limit($limit);
        }

        return $q->get();
    }

    public function find(int $id): ?array
    {
        return $this->db->table('backups')->where('id', '=', $id)->first();
    }

    /**
     * Restore. Destructive by design — the caller must pass a confirmation
     * token equal to `restore:<id>` to prove intent.
     *
     * @return array{ok: bool, statements?: int, error?: string}
     */
    public function restore(int $id, string $confirmationToken, ?User $actor = null, ?Request $request = null): array
    {
        if ($confirmationToken !== 'restore:' . $id) {
            return ['ok' => false, 'error' => 'Неверный токен подтверждения.'];
        }
        $row = $this->find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Резервная копия не найдена.'];
        }
        $path = (string) $row['path'];
        if (!is_file($path)) {
            // Fall back to a same-named file in the backup directory.
            $path = $this->path() . '/' . basename($path);
            if (!is_file($path)) {
                return ['ok' => false, 'error' => 'Файл резервной копии отсутствует на диске.'];
            }
        }

        // Safety net first.
        $this->create('db', 'Автоматическая копия перед восстановлением #' . $id, $actor, $request);

        $tmp = $this->config->getPath('storage') . '/app/.restore-' . bin2hex(random_bytes(4));
        try {
            Fs::ensureDir($tmp, 0700);
            $zipPath = $tmp . '/backup.zip';
            Fs::put($zipPath, (bool) $row['encrypted'] ? $this->decryptFile($path) : (string) file_get_contents($path), 0600);

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Архив повреждён.');
            }
            $zip->extractTo($tmp);
            $zip->close();

            $sqlFile = $tmp . '/database.sql';
            $sqliteFile = $tmp . '/database.sqlite';
            $statements = 0;

            if (is_file($sqlFile)) {
                $schema = new Schema($this->db, $this->logger);
                $statements = $schema->executeScript((string) file_get_contents($sqlFile));
            } elseif (is_file($sqliteFile) && $this->db->isSqlite()) {
                $target = (string) $this->db->settings()['database'];
                copy($sqliteFile, $target);
                @chmod($target, 0640);
                $statements = 1;
            } else {
                throw new \RuntimeException('В архиве нет database.sql — восстановление невозможно.');
            }

            $this->db->table('backups')->where('id', '=', $id)->update(['status' => 'restored']);
            $this->audit->record('backup.restore', $actor, [
                'subject_type' => 'backup', 'subject_id' => (string) $id, 'subject_label' => (string) $row['filename'],
                'meta' => ['statements' => $statements], 'is_security' => true,
            ], $request);

            return ['ok' => true, 'statements' => $statements];
        } catch (Throwable $e) {
            $this->logger->channel('app')->error('Restore failed', ['error' => $e->getMessage(), 'backup' => $id]);

            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            Fs::deleteDir($tmp);
        }
    }

    public function delete(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        if (is_file((string) $row['path'])) {
            @unlink((string) $row['path']);
        }
        $this->db->table('backups')->where('id', '=', $id)->delete();
        $this->audit->info('backup.delete', $actor, 'backup', (string) $id, (string) $row['filename'], $request);

        return true;
    }

    /** Keep only the newest N archives. */
    public function rotate(): int
    {
        $keep = max(1, $this->config->int('backup.keep', 14));
        $rows = $this->db->table('backups')->where('status', '=', 'done')->orderBy('id', 'desc')->get();
        $removed = 0;
        foreach (array_slice($rows, $keep) as $row) {
            if (is_file((string) $row['path'])) {
                @unlink((string) $row['path']);
            }
            $this->db->table('backups')->where('id', '=', (int) $row['id'])->delete();
            $removed++;
        }

        return $removed;
    }

    public function path(): string
    {
        $configured = $this->config->get('backup.path');

        return is_string($configured) && $configured !== '' ? $configured : $this->config->getPath('backups');
    }

    public function totalSize(): int
    {
        return (int) $this->db->table('backups')->where('status', '=', 'done')->sum('size_bytes');
    }

    public function lastBackupAt(): ?string
    {
        $value = $this->db->table('backups')->where('status', '=', 'done')->orderBy('id', 'desc')->value('created_at');

        return $value === null ? null : (string) $value;
    }

    /** Download helper: returns the archive contents as a string (for Response::file). */
    public function streamPath(int $id): ?string
    {
        $row = $this->find($id);
        if ($row === null || !is_file((string) $row['path'])) {
            return null;
        }

        return (string) $row['path'];
    }

    public function describeFilename(string $filename): string
    {
        return Str::squash($filename);
    }
}
