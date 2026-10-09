<?php

declare(strict_types=1);

namespace BasIp\Update;

use BasIp\Auth\User;
use BasIp\Core\App;
use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\HttpClient;
use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Db\Migrator;
use BasIp\Services\AuditService;
use BasIp\Services\BackupService;
use BasIp\Services\SettingsService;
use BasIp\Support\Arr;
use BasIp\Support\Fs;
use BasIp\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Self-update from the GitHub repository.
 *
 * Flow:
 *   1. `check()`  — GET /releases/latest, compare semver, cache for
 *                   `update.check_interval` (default 24 h).
 *   2. `download()` — fetch the zipball (or a release asset) into
 *                   `storage/app/updates`, verify SHA-256 when pinned.
 *   3. `install()` — maintenance flag → full backup → extract to a temp tree →
 *                   copy files over the live tree while *skipping* everything in
 *                   `update.preserve` → run migrations → clear caches →
 *                   snapshot for rollback.
 *   4. `rollback()` — restore the snapshot taken in step 3.
 *
 * The local config (`.bas-ip.config.local.php`), `.env`, `storage/` and the
 * install lock are never touched, so an update cannot wipe your credentials or
 * your data.
 */
final class UpdateService
{
    private const CACHE_KEY = 'update.latest';

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly HttpClient $http,
        private readonly Database $db,
        private readonly Cache $cache,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly BackupService $backups,
        private readonly Migrator $migrator,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->bool('update.enabled', true);
    }

    public function currentVersion(): string
    {
        $fromSettings = $this->settings->string('system.installed_version', '');

        return $fromSettings !== '' ? $fromSettings : $this->config->string('app.version', App::VERSION);
    }

    public function repo(): string
    {
        return (string) $this->config->string('update.repo', 'paulmann/BAS-IP');
    }

    /**
     * Look for a newer release.
     *
     * @return array{ok: bool, current: string, latest?: Release, update_available?: bool,
     *               checked_at?: string, error?: string, cached?: bool, channel?: string}
     */
    public function check(bool $force = false): array
    {
        $current = $this->currentVersion();
        if (!$this->enabled()) {
            return ['ok' => false, 'current' => $current, 'error' => 'Проверка обновлений отключена в конфигурации.'];
        }

        $interval = max(60, $this->config->int('update.check_interval', 86400));
        $lastCheck = $this->settings->string('update.last_check', '');
        $cached = $force ? null : $this->cache->get(self::CACHE_KEY);

        if (!$force && $cached !== null && is_array($cached) && $lastCheck !== '' && ($this->clock->now() - strtotime($lastCheck)) < $interval) {
            $release = Release::fromGitHub((array) ($cached['release'] ?? []), $this->repo());

            return [
                'ok' => true, 'current' => $current, 'latest' => $release, 'cached' => true,
                'update_available' => $release !== null && self::isNewer($release->version(), $current),
                'checked_at' => $lastCheck, 'channel' => $this->config->string('update.channel', 'stable'),
            ];
        }

        $api = str_replace('{repo}', $this->repo(), (string) $this->config->string('update.api_url', 'https://api.github.com/repos/{repo}'));
        $endpoint = $this->config->string('update.channel', 'stable') === 'beta'
            ? $api . '/releases?per_page=10'
            : $api . '/releases/latest';

        $token = $this->config->string('update.token', '');
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BAS-IP-Gate-Manager/' . $current,
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = $this->http->request('GET', $endpoint, [
            'headers' => $headers, 'timeout' => 20, 'retries' => 2, 'accept' => 'application/json',
        ]);

        $data = $response->json();
        if (!$response->ok() || $data === null) {
            // A 404 on /releases/latest simply means "no releases yet" — fall back to tags.
            if ($response->status === 404) {
                return $this->checkTags($current, $headers);
            }
            $this->logger->channel('update')->warning('Update check failed', ['status' => $response->status, 'body' => substr($response->body, 0, 300)]);

            return ['ok' => false, 'current' => $current, 'error' => $this->describeApiError($response->status, $response->body)];
        }

        $payload = $this->config->string('update.channel', 'stable') === 'beta' && is_array($data) && isset($data[0])
            ? (array) $data[0]
            : (array) $data;

        $release = Release::fromGitHub($payload, $this->repo());
        if ($release === null) {
            return ['ok' => false, 'current' => $current, 'error' => 'Не удалось разобрать ответ GitHub.'];
        }

        $this->cache->put(self::CACHE_KEY, ['release' => $payload], $interval);
        $this->settings->set('update.last_check', $this->clock->format());
        $this->settings->set('update.latest_version', $release->version());
        $this->settings->set('update.latest_notes', mb_substr($release->summary(4000), 0, 20000));
        $available = self::isNewer($release->version(), $current);
        $this->settings->set('update.available', $available);

        $this->logger->channel('update')->info('Update check', [
            'current' => $current, 'latest' => $release->version(), 'available' => $available,
        ]);

        return [
            'ok' => true, 'current' => $current, 'latest' => $release,
            'update_available' => $available, 'checked_at' => $this->clock->format(), 'cached' => false,
            'channel' => $this->config->string('update.channel', 'stable'),
        ];
    }

    /** @param array<string, string> $headers */
    private function checkTags(string $current, array $headers): array
    {
        $api = str_replace('{repo}', $this->repo(), (string) $this->config->string('update.api_url', 'https://api.github.com/repos/{repo}'));
        $response = $this->http->request('GET', $api . '/tags?per_page=20', ['headers' => $headers, 'timeout' => 20, 'retries' => 1]);
        $data = $response->json();
        if (!$response->ok() || !is_array($data) || $data === []) {
            return ['ok' => false, 'current' => $current, 'error' => 'В репозитории нет ни релизов, ни тегов.'];
        }

        $best = null;
        foreach ($data as $tag) {
            $name = ltrim((string) ($tag['name'] ?? ''), 'vV');
            if ($name === '' || !self::isSemver($name)) {
                continue;
            }
            if ($best === null || version_compare($name, $best, '>')) {
                $best = $name;
            }
        }
        if ($best === null) {
            return ['ok' => false, 'current' => $current, 'error' => 'Теги в репозитории не содержат версий.'];
        }

        $release = new Release(
            tag: 'v' . $best, name: 'v' . $best, body: '',
            url: 'https://github.com/' . $this->repo() . '/releases/tag/v' . $best,
            downloadUrl: str_replace('{repo}', $this->repo(), (string) $this->config->string('update.download_url')) !== ''
                ? str_replace(['{repo}', '{ref}'], [$this->repo(), 'v' . $best], (string) $this->config->string('update.download_url'))
                : 'https://api.github.com/repos/' . $this->repo() . '/zipball/v' . $best,
            publishedAt: '', prerelease: false,
        );

        $available = self::isNewer($best, $current);
        $this->settings->set('update.last_check', $this->clock->format());
        $this->settings->set('update.latest_version', $best);
        $this->settings->set('update.available', $available);

        return ['ok' => true, 'current' => $current, 'latest' => $release, 'update_available' => $available, 'checked_at' => $this->clock->format(), 'source' => 'tags'];
    }

    private function describeApiError(int $status, string $body): string
    {
        $data = json_decode($body, true);
        $message = is_array($data) ? (string) (Arr::get($data, 'message') ?? '') : '';

        return match (true) {
            $status === 403 && str_contains(strtolower($message), 'rate limit') => 'Превышен лимит запросов к GitHub API. Укажите update.token или повторите позже.',
            $status === 401 => 'GitHub отклонил токен (проверьте update.token).',
            $status === 404 => 'Релизы в репозитории не найдены.',
            $status === 0     => 'Нет связи с api.github.com (проверьте DNS/файрвол).',
            $message !== ''   => 'GitHub: ' . $message,
            default           => 'GitHub вернул HTTP ' . $status,
        };
    }

    /* ─────────────────────────── download ─────────────────────────── */

    /** @return array{ok: bool, path?: string, bytes?: int, sha256?: string, error?: string} */
    public function download(?Release $release = null, ?string $ref = null): array
    {
        $release ??= $this->latestRelease();
        if ($release === null && $ref === null) {
            return ['ok' => false, 'error' => 'Не указано, что скачивать. Сначала выполните проверку обновлений.'];
        }

        $url = $release?->downloadUrl
            ?? str_replace(['{repo}', '{ref}'], [$this->repo(), (string) $ref], (string) $this->config->string('update.download_url'));

        $dir = $this->config->getPath('updates');
        Fs::ensureDir($dir);
        $name = 'bas-ip-' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($release?->tag ?? $ref)) . '-' . $this->clock->format('Ymd-His') . '.zip';
        $path = $dir . '/' . $name;

        $token = $this->config->string('update.token', '');
        $headers = ['User-Agent' => 'BAS-IP-Gate-Manager', 'Accept' => 'application/vnd.github+json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $this->logger->channel('update')->info('Downloading release', ['url' => $url, 'target' => $path]);

        $response = $this->http->download($url, $path, [
            'headers' => $headers, 'timeout' => 300, 'follow' => true, 'max_redirects' => 5, 'verify_tls' => true,
        ]);

        if (!$response->ok() || !is_file($path) || Fs::size($path) < 1024) {
            @unlink($path);

            return ['ok' => false, 'error' => 'Не удалось скачать архив: ' . ($response->error ?? ('HTTP ' . $response->status))];
        }

        $sha = (string) (hash_file('sha256', $path) ?: '');
        $expected = $this->config->string('update.expected_sha256', '');
        if ($expected !== '' && !hash_equals(strtolower($expected), strtolower($sha))) {
            @unlink($path);

            return ['ok' => false, 'error' => 'Контрольная сумма не совпала — архив повреждён или подменён.', 'sha256' => $sha];
        }

        return ['ok' => true, 'path' => $path, 'bytes' => Fs::size($path), 'sha256' => $sha, 'version' => $release?->version()];
    }

    public function latestRelease(): ?Release
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['release'])) {
            $release = Release::fromGitHub((array) $cached['release'], $this->repo());
            if ($release !== null) {
                return $release;
            }
        }
        $check = $this->check(false);

        return $check['latest'] ?? null;
    }

    /* ─────────────────────────── install ─────────────────────────── */

    /**
     * Install an already-downloaded archive (or download it first).
     *
     * @return array{ok: bool, from?: string, to?: string, files?: int, migrations?: array<int, string>,
     *               snapshot?: string, error?: string, log?: array<int, string>}
     */
    public function install(?string $archivePath = null, ?User $actor = null, ?Request $request = null, bool $skipBackup = false): array
    {
        $log = [];
        $from = $this->currentVersion();

        try {
            if ($archivePath === null || $archivePath === '') {
                $download = $this->download();
                if (!$download['ok']) {
                    return ['ok' => false, 'error' => (string) $download['error'], 'log' => $log];
                }
                $archivePath = (string) $download['path'];
                $log[] = 'Архив скачан: ' . basename($archivePath) . ' (' . Str::bytes((int) ($download['bytes'] ?? 0)) . ' байт)';
            }
            if (!is_file($archivePath)) {
                return ['ok' => false, 'error' => 'Архив не найден: ' . $archivePath, 'log' => $log];
            }

            // ── 1. maintenance flag ───────────────────────────────────────
            $flag = $this->config->getPath('storage') . '/framework/maintenance.flag';
            if ($this->config->bool('update.maintenance_during_update', true)) {
                Fs::ensureDir(dirname($flag));
                Fs::put($flag, (string) json_encode([
                    'message' => 'Выполняется обновление системы. Шлагбаум работает в штатном режиме.',
                    'since' => $this->clock->format(),
                ], JSON_UNESCAPED_UNICODE), 0640);
                $log[] = 'Включён режим обслуживания';
            }

            // ── 2. snapshot for rollback ──────────────────────────────────
            $snapshot = $this->snapshot($from);
            $log[] = 'Снимок текущей версии: ' . basename($snapshot);

            // ── 3. backup ────────────────────────────────────────────────
            if (!$skipBackup && $this->config->bool('update.backup_before', true)) {
                $log[] = 'Создание резервной копии…';
                $result = $this->backups->create('db', 'Перед обновлением ' . $from, $actor, $request);
                $log[] = $result['ok'] ? 'Резервная копия: ' . ($result['file'] ?? '?') : '⚠ резервная копия не создана: ' . ($result['error'] ?? '?');
            }

            // ── 4. extract ───────────────────────────────────────────────
            $tmp = $this->config->getPath('updates') . '/extract-' . bin2hex(random_bytes(4));
            Fs::ensureDir($tmp, 0750);
            $this->extract($archivePath, $tmp);
            $source = $this->findSourceRoot($tmp);
            $log[] = 'Архив распакован';

            $newVersion = $this->detectVersion($source) ?? $from;

            // ── 5. copy ──────────────────────────────────────────────────
            $copied = $this->copyTree($source, $this->config->root());
            $log[] = sprintf('Скопировано файлов: %d', $copied);

            // ── 6. migrations ────────────────────────────────────────────
            $migrations = [];
            if ($this->config->bool('update.auto_migrate', true)) {
                $migrations = $this->migrator->migrate();
                $log[] = $migrations === [] ? 'Миграции: без изменений' : 'Применено миграций: ' . count($migrations);
            }

            // ── 7. housekeeping ──────────────────────────────────────────
            $this->cache->flush();
            $this->settings->set('system.installed_version', $newVersion);
            $this->settings->set('update.available', false);
            Fs::delete($flag);
            Fs::deleteDir($tmp);
            @unlink($archivePath);

            $this->logger->channel('update')->info('Update installed', ['from' => $from, 'to' => $newVersion, 'files' => $copied]);
            $this->audit->record('update.install', $actor, [
                'subject_type' => 'app', 'subject_id' => $newVersion, 'subject_label' => $from . ' → ' . $newVersion,
                'meta' => ['files' => $copied, 'migrations' => $migrations, 'snapshot' => $snapshot],
                'is_security' => true,
            ], $request);

            return ['ok' => true, 'from' => $from, 'to' => $newVersion, 'files' => $copied, 'migrations' => $migrations, 'snapshot' => $snapshot, 'log' => $log];
        } catch (Throwable $e) {
            $log[] = 'ОШИБКА: ' . $e->getMessage();
            $this->logger->channel('update')->error('Update failed', ['error' => $e->getMessage(), 'from' => $from]);

            // Try to bring the app back up.
            $flag = $this->config->getPath('storage') . '/framework/maintenance.flag';
            if (is_file($flag)) {
                Fs::delete($flag);
            }

            return ['ok' => false, 'error' => $e->getMessage(), 'from' => $from, 'log' => $log];
        }
    }

    /** Roll back to the snapshot taken before the last install. */
    public function rollback(?User $actor = null, ?Request $request = null): array
    {
        $dir = $this->snapshotsDir();
        $snapshots = Fs::glob($dir, '*.snapshot');
        if ($snapshots === []) {
            return ['ok' => false, 'error' => 'Нет снимков для отката.'];
        }
        usort($snapshots, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        $latest = $snapshots[0];

        try {
            $tmp = $latest . '.extract';
            Fs::ensureDir($tmp, 0750);
            $this->extract($latest, $tmp);
            $source = $this->findSourceRoot($tmp);
            $copied = $this->copyTree($source, $this->config->root());
            Fs::deleteDir($tmp);

            $version = $this->detectVersion($source) ?? 'unknown';
            $this->settings->set('system.installed_version', $version);
            $this->cache->flush();

            $this->audit->record('update.rollback', $actor, [
                'subject_type' => 'app', 'subject_id' => $version,
                'meta' => ['snapshot' => basename($latest), 'files' => $copied], 'is_security' => true,
            ], $request);

            return ['ok' => true, 'version' => $version, 'files' => $copied];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function snapshots(): array
    {
        $out = [];
        foreach (Fs::glob($this->snapshotsDir(), '*.snapshot') as $file) {
            $out[] = ['file' => basename($file), 'path' => $file, 'bytes' => Fs::size($file), 'at' => (int) filemtime($file)];
        }
        usort($out, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $out;
    }

    private function snapshot(string $version): string
    {
        $dir = $this->snapshotsDir();
        Fs::ensureDir($dir);
        $tmp = $dir . '/build-' . bin2hex(random_bytes(4));
        Fs::ensureDir($tmp, 0750);

        foreach (['src', 'public', 'config', 'database', 'resources', 'bin', 'README.md', 'CHANGELOG.md', 'composer.json'] as $item) {
            $source = $this->config->root() . '/' . $item;
            if (!file_exists($source)) {
                continue;
            }
            if (is_dir($source)) {
                Fs::copyDir($source, $tmp . '/' . $item);
            } else {
                Fs::ensureDir(dirname($tmp . '/' . $item));
                copy($source, $tmp . '/' . $item);
            }
        }

        $file = sprintf('%s/%s-%s.snapshot', $dir, preg_replace('/[^A-Za-z0-9._-]/', '', $version), $this->clock->format('Ymd-His'));
        $this->makeZip($tmp, $file);
        Fs::deleteDir($tmp);
        $this->rotateSnapshots();

        return $file;
    }

    private function rotateSnapshots(): void
    {
        $keep = max(1, $this->config->int('update.keep_releases', 5));
        $files = Fs::glob($this->snapshotsDir(), '*.snapshot');
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }
    }

    private function snapshotsDir(): string
    {
        return $this->config->getPath('updates') . '/snapshots';
    }

    private function makeZip(string $dir, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать снимок: ' . $target);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($dir) + 1);
            $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        }
        $zip->close();
    }

    private function extract(string $archive, string $target): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($archive);
        if ($result !== true) {
            throw new RuntimeException('Не удалось открыть архив (код ' . $result . ').');
        }

        // Zip-slip defence: reject entries escaping the target directory.
        $realTarget = realpath($target) ?: $target;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, "\0")) {
                throw new RuntimeException('Архив содержит недопустимое имя файла.');
            }
            $resolved = realpath($realTarget . '/' . $name);
            $parent = realpath(dirname($realTarget . '/' . $name));
            if ($resolved !== false && !str_starts_with($resolved, $realTarget)) {
                throw new RuntimeException('Архив содержит путь за пределами каталога: ' . $name);
            }
            if ($parent !== false && !str_starts_with($parent, $realTarget) && str_contains($name, '..')) {
                throw new RuntimeException('Архив содержит путь за пределами каталога: ' . $name);
            }
        }

        if (!$zip->extractTo($target)) {
            $zip->close();
            throw new RuntimeException('Не удалось распаковать архив.');
        }
        $zip->close();
    }

    /** GitHub zipballs wrap everything in `owner-repo-sha/`. */
    private function findSourceRoot(string $dir): string
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            $candidate = $dir . '/' . $entries[0];
            if (is_dir($candidate . '/src') || is_file($candidate . '/composer.json')) {
                return $candidate;
            }
        }

        return $dir;
    }

    /**
     * Copy the new tree over the live one, skipping everything listed in
     * `update.preserve` and never overwriting local configuration.
     */
    private function copyTree(string $source, string $target): int
    {
        $preserve = (array) $this->config->get('update.preserve', []);
        $copied = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $relative = str_replace('\\', '/', $relative);

            if ($this->isPreserved($relative, $preserve)) {
                continue;
            }
            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                Fs::ensureDir($destination);
                continue;
            }
            // Never overwrite a live local config, even if the archive carries a sample.
            if (is_file($destination) && $this->isLocalConfig($relative)) {
                continue;
            }
            Fs::ensureDir(dirname($destination));
            if (@copy($item->getPathname(), $destination)) {
                @chmod($destination, $this->isExecutable($relative) ? 0750 : 0640);
                $copied++;
            }
        }

        return $copied;
    }

    /** @param array<int, string> $preserve */
    private function isPreserved(string $relative, array $preserve): bool
    {
        foreach ($preserve as $pattern) {
            $pattern = trim((string) $pattern, '/');
            if ($pattern === '') {
                continue;
            }
            if ($relative === $pattern || str_starts_with($relative, $pattern . '/')) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $relative)) {
                return true;
            }
        }
        foreach (['.git', 'node_modules', 'vendor', '.DS_Store'] as $skip) {
            if (str_starts_with($relative, $skip . '/') || $relative === $skip) {
                return true;
            }
        }

        return false;
    }

    private function isLocalConfig(string $relative): bool
    {
        return str_ends_with($relative, '.local.php')
            || basename($relative) === '.env'
            || str_ends_with($relative, '.secrets.php')
            || str_starts_with($relative, 'storage/');
    }

    private function isExecutable(string $relative): bool
    {
        return str_starts_with($relative, 'bin/') || str_ends_with($relative, '.sh');
    }

    private function detectVersion(string $sourceRoot): ?string
    {
        $file = $sourceRoot . '/config/config.php';
        if (is_file($file)) {
            $contents = (string) file_get_contents($file);
            if (preg_match("/'version'\\s*=>\\s*'([^']+)'/", $contents, $m)) {
                return $m[1];
            }
        }
        $composer = $sourceRoot . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (isset($data['version'])) {
                return (string) $data['version'];
            }
        }
        $const = $sourceRoot . '/src/BasIp/Core/App.php';
        if (is_file($const) && preg_match("/VERSION\\s*=\\s*'([^']+)'/", (string) file_get_contents($const), $m)) {
            return $m[1];
        }

        return null;
    }

    /* ─────────────────────────── semver ─────────────────────────── */

    public static function isSemver(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)?([-+][0-9A-Za-z.\-]+)?$/', $version);
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        $candidate = self::normalizeVersion($candidate);
        $current   = self::normalizeVersion($current);
        if ($candidate === '' || $current === '') {
            return false;
        }

        return version_compare($candidate, $current, '>');
    }

    private static function normalizeVersion(string $version): string
    {
        $version = ltrim(trim($version), 'vV');
        // Keep only the numeric core for comparison; drop -beta/+build metadata.
        $version = (string) preg_replace('/[-+].*$/', '', $version);

        return self::isSemver($version) ? $version : '';
    }

    /** Compare two versions for display. */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalizeVersion($a) ?: '0.0.0', self::normalizeVersion($b) ?: '0.0.0');
    }
}
