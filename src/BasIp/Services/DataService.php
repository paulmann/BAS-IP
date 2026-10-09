<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\App;
use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Device\DeviceManager;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Support\Fs;
use BasIp\Support\Ip;
use BasIp\Support\Str;
use Throwable;

/**
 * System-level operations: health checks, self-diagnostics, cache and queue
 * maintenance, scheduled jobs and the "everything you need to know" report
 * shown on the «Система» page.
 */
final class DataService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly Cache $cache,
        private readonly SettingsService $settings,
        private readonly ResidentRepository $residents,
        private readonly DeviceManager $devices,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Liveness/readiness probe for `/health`.
     *
     * @return array{status: string, checks: array<string, array<string, mixed>>, version: string, time: string}
     */
    public function health(bool $deep = false): array
    {
        $checks = [];

        $checks['php'] = [
            // Hard floor is what the code actually needs; 8.5 is the recommended target.
            'ok' => PHP_VERSION_ID >= 80200,
            'value' => PHP_VERSION,
            'message' => match (true) {
                PHP_VERSION_ID >= 80500 => null,
                PHP_VERSION_ID >= 80200 => 'Работает. Рекомендуемая версия — PHP 8.5.',
                default => 'Требуется PHP 8.2+',
            },
        ];

        foreach (['curl', 'pdo', 'mbstring', 'json', 'openssl', 'sodium', 'zip'] as $ext) {
            $checks['ext:' . $ext] = ['ok' => extension_loaded($ext), 'value' => extension_loaded($ext) ? 'loaded' : 'missing'];
        }

        // Database
        try {
            $info = $this->db->serverInfo();
            $checks['database'] = ['ok' => $this->db->ping(), 'value' => ($info['pdo_driver'] ?? '?') . ' ' . ($info['version'] ?? '?'), 'message' => null];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'value' => 'unavailable', 'message' => $e->getMessage()];
        }

        // Schema
        try {
            $tables = $this->db->tables();
            $checks['schema'] = [
                'ok' => in_array('residents', $tables, true) && in_array('gate_events', $tables, true),
                'value' => count($tables) . ' таблиц',
                'message' => count($tables) < 20 ? 'Выполните миграции: php bin/bas-ip migrate' : null,
            ];
        } catch (Throwable $e) {
            $checks['schema'] = ['ok' => false, 'value' => 'n/a', 'message' => $e->getMessage()];
        }

        // Writable storage
        foreach (['logs' => 'logs', 'cache' => 'cache', 'sessions' => 'sessions', 'exports' => 'exports', 'backups' => 'backups'] as $key => $pathKey) {
            $path = $this->config->getPath($pathKey);
            $writable = Fs::isWritable($path);
            $checks['writable:' . $key] = ['ok' => $writable, 'value' => Fs::relative($path, $this->config->root()), 'message' => $writable ? null : 'Нет прав на запись'];
        }

        $checks['app_key'] = [
            'ok' => $this->config->appKey() !== null,
            'value' => $this->config->appKey() !== null ? 'настроен' : 'не задан',
            'message' => $this->config->appKey() === null ? 'Выполните php bin/bas-ip key:generate' : null,
        ];

        $checks['local_config'] = [
            'ok' => true,
            'value' => $this->config->hasLocalFile() ? implode(', ', $this->config->sources()) : 'отсутствует',
            'message' => $this->config->hasLocalFile() ? null : 'Создайте .bas-ip.config.local.php из примера',
        ];

        $checks['secrets_encrypted'] = [
            'ok' => $this->config->secrets()->encrypted() || $this->config->secrets()->names() === [],
            'value' => count($this->config->secrets()->names()) . ' секретов',
            'message' => $this->config->secrets()->encrypted() ? null : 'Секреты хранятся без шифрования — задайте app.key',
        ];

        if ($deep) {
            $device = $this->devices->default();
            if ($device !== null) {
                $result = $this->devices->driver($device)->ping();
                $checks['device'] = [
                    'ok' => $result->ok,
                    'value' => $device->label() . ' (' . $device->driverLabel() . ')',
                    'message' => $result->ok ? null : $result->message,
                    'ms' => round($result->durationMs, 1),
                ];
            } else {
                $checks['device'] = ['ok' => false, 'value' => 'не настроено', 'message' => 'Добавьте устройство в разделе «Устройства»'];
            }
        }

        $failed = array_filter($checks, static fn (array $c): bool => !($c['ok'] ?? false));
        $critical = ['php', 'database', 'schema', 'app_key'];
        $status = 'ok';
        foreach ($failed as $name => $check) {
            if (in_array($name, $critical, true)) {
                $status = 'down';
                break;
            }
            $status = 'degraded';
        }

        return [
            'status'  => $status,
            'checks'  => $checks,
            'version' => App::VERSION,
            'time'    => $this->clock->format(DATE_ATOM),
            'env'     => $this->config->string('app.env', 'production'),
        ];
    }

    /**
     * Extended self-diagnostic used by «Система → Диагностика» and `bin/bas-ip doctor`.
     *
     * @return array<int, array{level: string, area: string, message: string, hint?: string}>
     */
    public function doctor(): array
    {
        $issues = [];
        $add = function (string $level, string $area, string $message, ?string $hint = null) use (&$issues): void {
            $issues[] = ['level' => $level, 'area' => $area, 'message' => $message] + ($hint !== null ? ['hint' => $hint] : []);
        };

        // ── PHP & extensions ────────────────────────────────────────────
        if (PHP_VERSION_ID < 80200) {
            $add('error', 'php', 'Установлен PHP ' . PHP_VERSION . ' — требуется 8.2 или новее.', 'Обновите PHP: https://www.php.net/releases/');
        } elseif (PHP_VERSION_ID < 80500) {
            $add('info', 'php', 'Установлен PHP ' . PHP_VERSION . '. Рекомендуемая версия — 8.5.', 'Обновите PHP: https://www.php.net/releases/');
        }
        foreach (['curl' => 'работа с панелью и внешними API', 'pdo' => 'доступ к БД', 'mbstring' => 'кириллица', 'sodium' => 'шифрование секретов', 'zip' => 'резервные копии и обновления', 'intl' => 'форматирование дат и чисел'] as $ext => $why) {
            if (!extension_loaded($ext)) {
                $add($ext === 'intl' ? 'warn' : 'error', 'php', 'Не загружено расширение `' . $ext . '` (' . $why . ').', 'Установите php-' . $ext);
            }
        }
        if (!defined('PASSWORD_ARGON2ID')) {
            $add('warn', 'php', 'Argon2id недоступен — пароли хешируются bcrypt.', 'Соберите PHP с libargon2 или установите php-sodium.');
        }
        foreach (['exec', 'shell_exec', 'proc_open'] as $fn) {
            if (in_array($fn, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                $add('info', 'php', 'Функция `' . $fn . '` отключена — резервные копии будут создаваться встроенным дампером PHP.', null);
            }
        }

        // ── Configuration & secrets ─────────────────────────────────────
        if ($this->config->appKey() === null) {
            $add('error', 'config', 'Не задан app.key — подписанные ссылки, CSRF и шифрование секретов не работают.', 'php bin/bas-ip key:generate');
        }
        if (!$this->config->hasLocalFile()) {
            $add('warn', 'config', 'Локальный конфиг не найден — используются значения по умолчанию из config/config.php.',
                'cp .bas-ip.config.local.php.example .bas-ip.config.local.php');
        }
        if ($this->config->bool('app.debug') && $this->config->string('app.env') === 'production') {
            $add('error', 'config', 'app.debug = true в production. Показываются стектрейсы и SQL.', 'Установите app.debug => false.');
        } elseif ($this->config->bool('app.debug')) {
            $add('info', 'config', 'app.debug = true — нормально для разработки, но не для боевого сервера.');
        }
        if ($this->config->string('app.env') !== 'production') {
            $add('warn', 'config', 'app.env = ' . $this->config->string('app.env') . '.', 'Для боевого сервера установите production.');
        }
        if (!$this->config->secrets()->encrypted() && $this->config->secrets()->names() !== []) {
            $add('warn', 'config', 'Секреты хранятся без шифрования (нет app.key).', 'Задайте app.key и выполните php bin/bas-ip secret:reencrypt');
        }
        $legacy = $this->config->legacyDetected();
        if ($legacy !== []) {
            $add('warn', 'config', 'Обнаружен устаревший config.php версии 1.x — значения автоматически преобразованы.',
                'Перенесите их в .bas-ip.config.local.php и удалите старый файл.');
        }

        // ── Filesystem & permissions ────────────────────────────────────
        foreach (['logs', 'cache', 'sessions', 'tokens', 'exports', 'backups', 'updates'] as $key) {
            $path = $this->config->getPath($key);
            if (!Fs::isWritable($path)) {
                $add('error', 'fs', 'Каталог недоступен для записи: ' . Fs::relative($path, $this->config->root()), 'chown -R www-data:www-data storage && chmod -R 770 storage');
            }
        }
        if (is_file($this->config->root() . '/config/config.php')) {
            $perms = fileperms($this->config->root() . '/config/config.php') & 0777;
            if ($perms & 0007) {
                $add('warn', 'fs', 'config/config.php доступен на запись всем.', 'chmod 640 config/config.php');
            }
        }
        $local = $this->config->localFilePath();
        if (is_file($local)) {
            $perms = fileperms($local) & 0777;
            if ($perms & 0077) {
                $add('warn', 'fs', '.bas-ip.config.local.php доступен другим пользователям (' . decoct($perms) . ').', 'chmod 600 ' . basename($local));
            }
        }
        foreach (['.git', 'config/config.php', '.env', 'storage'] as $sensitive) {
            $public = $this->config->getPath('public') . '/' . $sensitive;
            if (file_exists($public)) {
                $add('error', 'fs', 'В web-каталоге лежит «' . $sensitive . '» — он доступен из интернета!', 'Переместите файл за пределы public/.');
            }
        }
        if (!is_file($this->config->getPath('public') . '/.htaccess') && $this->isApache()) {
            $add('warn', 'fs', 'Нет public/.htaccess — прямые обращения к index.php не перенаправляются.', 'Используйте deploy/apache/.htaccess.');
        }

        // ── Database ────────────────────────────────────────────────────
        try {
            $info = $this->db->serverInfo();
            if ($this->db->isMySql()) {
                $version = (string) ($info['version'] ?? '');
                if (str_contains($version, 'MariaDB')) {
                    if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '10.6', '<')) {
                        $add('warn', 'db', 'MariaDB ' . $version . ' — рекомендуется 10.6+ (CHECK-ограничения, JSON-функции).');
                    }
                } elseif (version_compare($version, '8.0', '<')) {
                    $add('warn', 'db', 'MySQL ' . $version . ' — рекомендуется 8.0+.');
                }
            }
            if ($this->db->isMySql()) {
                $charset = (string) $this->db->scalar('SELECT @@character_set_database', [], '');
                if ($charset !== '' && stripos($charset, 'utf8mb4') === false) {
                    $add('error', 'db', 'База данных использует кодировку ' . $charset . ' — эмодзи и часть кириллицы будут потеряны.',
                        'ALTER DATABASE … CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
                }
            } elseif ($this->db->isPgsql()) {
                $encoding = (string) $this->db->scalar("SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = current_database()", [], '');
                if ($encoding !== '' && stripos($encoding, 'utf8') === false) {
                    $add('error', 'db', 'Кодировка базы — ' . $encoding . '. Требуется UTF8.', 'Пересоздайте БД с ENCODING \'UTF8\'.');
                }
            }
            $tables = $this->db->tables();
            if (!in_array('residents', $tables, true)) {
                $add('error', 'db', 'Схема не создана.', 'php bin/bas-ip migrate');
            } else {
                $missing = array_diff(['users', 'residents', 'contacts', 'cars', 'charges', 'invoices', 'payments', 'gate_events', 'guest_passes', 'audit_log', 'outbox', 'settings'], $tables);
                if ($missing !== []) {
                    $add('warn', 'db', 'Отсутствуют таблицы: ' . implode(', ', $missing), 'php bin/bas-ip migrate');
                }
            }
            $size = $this->db->sizeBytes();
            if ($size > 2 * 1024 * 1024 * 1024) {
                $add('info', 'db', 'Размер базы — ' . Str::bytes($size) . '. Настройте ротацию журнала шлагбаума.', 'gate:purge --days=365');
            }
        } catch (Throwable $e) {
            $add('error', 'db', 'Нет соединения с базой данных: ' . $e->getMessage(), 'Проверьте db.connections в .bas-ip.config.local.php');
        }

        // ── Security ────────────────────────────────────────────────────
        if ($this->config->list('auth.ip_allowlist.ips') !== [] && !$this->config->bool('auth.ip_allowlist.enabled')) {
            $add('info', 'security', 'Список разрешённых IP задан, но auth.ip_allowlist.enabled = false.');
        }
        $admins = (int) $this->db->table('users')->where('role', '=', 'admin')->whereNull('deleted_at')->count();
        if ($admins === 0) {
            $add('error', 'security', 'В системе нет ни одного администратора.', 'php bin/bas-ip user:create --role=admin');
        }
        $without2fa = (int) $this->db->table('users')->whereIn('role', ['admin', 'operator'])->where('totp_enabled', '=', 0)->whereNull('deleted_at')->count();
        if ($without2fa > 0) {
            $add('warn', 'security', sprintf('%d %s без двухфакторной аутентификации.', $without2fa, Str::plural($without2fa, 'сотрудник', 'сотрудника', 'сотрудников')), 'Профиль → Безопасность → 2FA');
        }
        $weak = (int) $this->db->table('users')->where('must_change_password', '=', 1)->whereNull('deleted_at')->count();
        if ($weak > 0) {
            $add('info', 'security', $weak . ' учётных записей требуют смены пароля при входе.');
        }
        if (!$this->config->bool('app.behind_proxy') && $this->config->string('app.url') !== '' && str_starts_with($this->config->string('app.url'), 'https://')) {
            $add('info', 'security', 'app.url использует HTTPS, но app.behind_proxy = false — cookie Secure может не выставиться.', 'Установите app.behind_proxy => true за реверс-прокси.');
        }

        // ── Device ──────────────────────────────────────────────────────
        $device = $this->devices->default();
        if ($device === null) {
            $add('warn', 'device', 'Устройство не настроено.', 'Раздел «Устройства» → Добавить');
        } else {
            if ($device->baseUrl === null || $device->baseUrl === '') {
                $add('warn', 'device', 'У устройства «' . $device->label() . '» не указан base_url.');
            } elseif (!filter_var($device->baseUrl, FILTER_VALIDATE_URL)) {
                $add('error', 'device', 'Некорректный base_url: ' . $device->baseUrl);
            } elseif (str_starts_with($device->baseUrl, 'http://') && !$this->isPrivateUrl($device->baseUrl)) {
                $add('warn', 'device', 'Панель доступна по http:// из публичной сети — учётные данные передаются открытым текстом.', 'Используйте HTTPS или VPN.');
            }
            if ($device->driver === 'dryrun') {
                $add('warn', 'device', 'Активен драйвер-эмулятор: шлагбаум НЕ открывается по-настоящему.', 'Переключите driver на basip в production.');
            }
        }

        // ── Data quality ────────────────────────────────────────────────
        $active = $this->residents->countActive();
        if ($active === 0) {
            $add('warn', 'data', 'Нет активных жителей — расчёт оплаты невозможен.', 'Импортируйте базу: «Данные» → Импорт');
        }
        $noContact = (int) $this->db->table('residents')->where('is_active', '=', 1)->whereNull('phone')->whereNull('email')->whereNull('deleted_at')->count();
        if ($noContact > 0) {
            $add('info', 'data', sprintf('%d %s не получат уведомления.', $noContact, Str::plural($noContact, 'активный житель', 'активных жителя', 'активных жителей')));
        }
        $duplicates = $this->residents->hasDuplicates();
        if ($duplicates !== []) {
            $add('warn', 'data', 'Найдено потенциальных дублей: ' . count($duplicates), 'Проверьте совпадающие ФИО + квартиру.');
        }

        // ── Operations ──────────────────────────────────────────────────
        $lastBackup = $this->db->table('backups')->where('status', '=', 'done')->orderBy('id', 'desc')->value('created_at');
        if ($lastBackup === null) {
            $add('warn', 'backup', 'Резервных копий ещё не было.', 'php bin/bas-ip backup:create');
        } elseif (strtotime((string) $lastBackup) < $this->clock->now() - 8 * 86400) {
            $add('warn', 'backup', 'Последняя резервная копия — ' . date('d.m.Y', (int) strtotime((string) $lastBackup)) . '.', 'Настройте cron: 0 3 * * * php bin/bas-ip backup:create');
        }
        $failedQueue = (int) $this->db->table('outbox')->where('status', '=', 'failed')->count();
        if ($failedQueue > 0) {
            $add('warn', 'queue', 'В очереди ' . $failedQueue . ' недоставленных уведомлений.', '«Уведомления» → Повторить');
        }
        $pendingQueue = (int) $this->db->table('outbox')->where('status', '=', 'pending')->count();
        if ($pendingQueue > 500) {
            $add('warn', 'queue', 'В очереди накопилось ' . $pendingQueue . ' сообщений — работник не успевает.', 'Запустите bin/bas-ip queue:work как сервис systemd.');
        }
        if (!$this->isCronConfigured()) {
            $add('info', 'cron', 'Нет признаков настроенного cron (последний запуск планировщика не зафиксирован).', 'crontab -e → * * * * * php /path/bin/bas-ip schedule:run');
        }

        // ── Updates ─────────────────────────────────────────────────────
        if ($this->config->bool('update.enabled') && $this->settings->bool('update.available', false)) {
            $add('info', 'update', 'Доступна новая версия ' . $this->settings->string('update.latest_version', '') . '.', 'Раздел «Обновления» → Установить');
        }

        if ($issues === []) {
            $add('ok', 'system', 'Проблем не обнаружено — система готова к работе.');
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    public function systemInfo(): array
    {
        $info = $this->db->serverInfo();

        return [
            'app' => [
                'name' => $this->config->string('app.name'),
                'version' => App::VERSION,
                'codename' => App::CODENAME,
                'env' => $this->config->string('app.env'),
                'debug' => $this->config->bool('app.debug'),
                'timezone' => $this->config->string('app.timezone'),
                'locale' => $this->config->string('ui.language'),
                'installed_at' => $this->settings->get('system.installed_at'),
                'installed_version' => $this->settings->get('system.installed_version'),
                'url' => $this->config->string('app.url'),
                'behind_proxy' => $this->config->bool('app.behind_proxy'),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'opcache' => function_exists('opcache_get_status') ? (bool) (opcache_get_status(false)['opcache_enabled'] ?? false) : false,
                'extensions' => get_loaded_extensions(),
                'recommended' => '8.5.x',
            ],
            'server' => [
                'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'hostname' => (string) gethostname(),
                'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'cli'),
                'disk_free' => Str::bytes((float) @disk_free_space($this->config->root())),
                'disk_total' => Str::bytes((float) @disk_total_space($this->config->root())),
                'load' => function_exists('sys_getloadavg') ? array_map(static fn ($v) => round((float) $v, 2), (array) sys_getloadavg()) : [],
            ],
            'database' => $info + [
                'size' => Str::bytes($this->db->sizeBytes()),
                'tables' => count($this->db->tables()),
                'prefix' => $this->db->tablePrefix() ?: '(нет)',
            ],
            'storage' => [
                'logs' => Str::bytes(Fs::size($this->config->getPath('logs'))),
                'cache' => Str::bytes(Fs::size($this->config->getPath('cache'))),
                'backups' => Str::bytes(Fs::size($this->config->getPath('backups'))),
                'exports' => Str::bytes(Fs::size($this->config->getPath('exports'))),
                'sessions' => Str::bytes(Fs::size($this->config->getPath('sessions'))),
            ],
            'counters' => [
                'residents' => $this->residents->countAll(),
                'residents_active' => $this->residents->countActive(),
                'cars' => (int) $this->db->table('cars')->count(),
                'gate_events' => (int) $this->db->table('gate_events')->count(),
                'guest_passes' => (int) $this->db->table('guest_passes')->count(),
                'invoices' => (int) $this->db->table('invoices')->count(),
                'payments' => (int) $this->db->table('payments')->count(),
                'audit_rows' => (int) $this->db->table('audit_log')->count(),
                'users' => (int) $this->db->table('users')->whereNull('deleted_at')->count(),
                'devices' => count($this->devices->all(false)),
            ],
            'config_sources' => $this->config->sources(),
        ];
    }

    /** @return array<string, mixed> */
    public function tableSizes(): array
    {
        $out = [];
        foreach ($this->db->tables() as $table) {
            try {
                $count = (int) $this->db->table($table)->count();
            } catch (Throwable) {
                $count = 0;
            }
            $out[$table] = $count;
        }
        arsort($out);

        return $out;
    }

    /* ─────────────────────────── maintenance ─────────────────────────── */

    public function clearCache(?User $actor = null, ?Request $request = null): int
    {
        $count = $this->cache->flush();
        $this->cache->forget('gate.status.default');
        $this->audit->info('system.cache_clear', $actor, 'system', 'cache', null, $request, ['entries' => $count]);

        return $count;
    }

    public function clearExpiredFiles(): array
    {
        $removed = ['exports' => 0, 'cache' => 0, 'sessions' => 0, 'logs' => 0, 'updates' => 0];
        $now = $this->clock->now();

        foreach (Fs::glob($this->config->getPath('exports'), '*') as $file) {
            if ((int) filemtime($file) < $now - 86400) {
                @unlink($file) && $removed['exports']++;
            }
        }
        $removed['cache'] = $this->cache->gc();
        foreach (Fs::glob($this->config->getPath('sessions'), 'sess_*') as $file) {
            if ((int) filemtime($file) < $now - 86400 * 2) {
                @unlink($file) && $removed['sessions']++;
            }
        }
        foreach (Fs::glob($this->config->getPath('updates'), '*.zip') as $file) {
            if ((int) filemtime($file) < $now - 86400 * 7) {
                @unlink($file) && $removed['updates']++;
            }
        }

        return $removed;
    }

    /** Purge old rows from high-volume tables according to the retention policy. */
    public function prune(array $retention = [], ?User $actor = null): array
    {
        $defaults = [
            'gate_events'    => (int) ($retention['gate_events'] ?? 730),
            'audit_log'      => (int) ($retention['audit_log'] ?? $this->config->int('security.audit.retention_days', 365)),
            'login_attempts' => (int) ($retention['login_attempts'] ?? 90),
            'outbox'         => (int) ($retention['outbox'] ?? 60),
            'billing_history' => (int) ($retention['billing_history'] ?? 1825),
        ];

        $result = [];
        foreach ($defaults as $table => $days) {
            if ($days <= 0) {
                continue;
            }
            $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $days * 86400);
            $column = match ($table) {
                'gate_events'   => 'occurred_at',
                'audit_log'     => 'occurred_at',
                'login_attempts' => 'created_at',
                'outbox'        => 'created_at',
                'billing_history' => 'occurred_at',
                default         => 'created_at',
            };
            try {
                $query = $this->db->table($table)->where($column, '<', $cutoff);
                if ($table === 'outbox') {
                    $query = $query->whereIn('status', ['sent', 'failed', 'skipped', 'cancelled']);
                }
                $result[$table] = $query->delete();
            } catch (Throwable $e) {
                $result[$table] = 'ошибка: ' . $e->getMessage();
            }
        }

        $this->audit->info('system.prune', $actor, 'system', 'retention', null, null, $result);

        return $result;
    }

    /**
     * Everything the nightly cron should do.
     *
     * @return array<string, mixed>
     */
    public function runScheduledJobs(): array
    {
        $report = ['at' => $this->clock->format(), 'jobs' => []];

        $report['jobs']['mark_overdue'] = $this->safe(fn () => $this->db->table('invoices')
            ->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $this->clock->format('Y-m-d'))
            ->where('balance', '>', 0)
            ->update(['status' => 'overdue', 'updated_at' => $this->clock->format()]));

        $report['jobs']['expire_guest_passes'] = $this->safe(fn () => $this->db->table('guest_passes')
            ->where('status', '=', 'active')->where('valid_until', '<', $this->clock->format())
            ->update(['status' => 'expired', 'updated_at' => $this->clock->format()]));

        $report['jobs']['clear_files'] = $this->safe(fn () => array_sum($this->clearExpiredFiles()));
        $report['jobs']['purge_sessions'] = $this->safe(fn () => $this->db->table('sessions_meta')
            ->where('expires_at', '<', $this->clock->format())->delete());
        $report['jobs']['purge_login_attempts'] = $this->safe(fn () => $this->db->table('login_attempts')
            ->where('created_at', '<', $this->clock->format('Y-m-d H:i:s', $this->clock->now() - 90 * 86400))->delete());
        $report['jobs']['rate_limit_gc'] = $this->safe(fn () => 0);

        $this->settings->set('system.last_cron', $this->clock->format());
        $this->logger->channel('app')->info('Scheduled jobs finished', ['jobs' => $report['jobs']]);

        return $report;
    }

    public function lastCronRun(): ?string
    {
        $value = $this->settings->get('system.last_cron');

        return is_string($value) ? $value : null;
    }

    private function isCronConfigured(): bool
    {
        $last = $this->lastCronRun();

        return $last !== null && strtotime($last) > $this->clock->now() - 86400 * 2;
    }

    /** Maintenance mode helpers. */
    public function maintenanceFlag(): string
    {
        return $this->config->getPath('storage') . '/framework/maintenance.flag';
    }

    public function isMaintenance(): bool
    {
        return is_file($this->maintenanceFlag());
    }

    public function setMaintenance(bool $on, string $message = ''): void
    {
        if ($on) {
            Fs::ensureDir(dirname($this->maintenanceFlag()));
            Fs::put($this->maintenanceFlag(), (string) json_encode([
                'message' => $message !== '' ? $message : null, 'since' => $this->clock->format(),
            ], JSON_UNESCAPED_UNICODE), 0640);
        } else {
            Fs::delete($this->maintenanceFlag());
        }
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function isApache(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 'apache');
    }

    private function isPrivateUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || !Ip::isValid($host)) {
            return true;      // hostname — assume an internal DNS name
        }

        return Ip::isPrivate($host);
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return 'ошибка: ' . $e->getMessage();
        }
    }
}
