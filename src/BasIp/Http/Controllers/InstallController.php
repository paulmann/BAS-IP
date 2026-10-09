<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Auth\AuthService;
use BasIp\Core\App;
use BasIp\Core\Clock;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Db\Database;
use BasIp\Db\Migrator;
use BasIp\Device\DeviceManager;
use BasIp\Http\Controller;
use BasIp\Services\SettingsService;
use BasIp\Support\Fs;
use Throwable;

/**
 * Web installer.
 *
 * Runs once, writes `.bas-ip.config.local.php` (git-ignored, chmod 600),
 * generates the application key, applies migrations, creates the first admin
 * and drops `public/install.lock`. After that every route here returns 403 —
 * the installer can only be re-enabled by deleting the lock file on the server.
 */
final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        if ($this->isLocked()) {
            return $this->view('errors/403', [
                'title' => 'Установка завершена',
                'message' => 'Система уже установлена. Для повторного запуска удалите public/install.lock.',
            ], 403);
        }

        return $this->view('install/index', [
            'title' => 'Установка BAS-IP Gate Manager',
            'layout' => 'auth',
            'requirements' => $this->requirements(),
            'step' => $request->str('step', 'welcome'),
            'localFile' => Fs::relative($this->config()->localFilePath(), $this->config()->root()),
            'version' => App::VERSION,
            'suggestedAdmin' => $this->suggestAdminLogin(),
        ]);
    }

    public function run(Request $request): Response
    {
        if ($this->isLocked()) {
            return $this->jsonError('Система уже установлена.', 403);
        }

        $step = $request->str('step', 'all');
        $log = [];

        try {
            // ── 1. Collect and validate ───────────────────────────────────
            $db = [
                'driver'   => $request->str('db_driver', 'mysql'),
                'host'     => $request->str('db_host', '127.0.0.1'),
                'port'     => $request->int('db_port', 0) ?: null,
                'database' => $request->str('db_database', 'bas_ip'),
                'username' => $request->str('db_username', 'bas_ip'),
                'password' => (string) $request->input('db_password', ''),
                'socket'   => $request->str('db_socket', ''),
                'prefix'   => $request->str('db_prefix', ''),
            ];
            if (!in_array($db['driver'], ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new \RuntimeException('Неподдерживаемый драйвер БД.');
            }
            if ($db['driver'] === 'sqlite') {
                $db['database'] = $db['database'] !== '' ? $db['database'] : 'storage/app/bas-ip.sqlite';
            }

            $admin = [
                'login' => $request->str('admin_login', 'admin'),
                'password' => (string) $request->input('admin_password', ''),
                'email' => $request->str('admin_email', ''),
                'full_name' => $request->str('admin_name', 'Администратор'),
            ];

            $device = [
                'driver'   => $request->str('device_driver', 'basip'),
                'base_url' => $request->str('device_base_url', ''),
                'login'    => $request->str('device_login', 'admin'),
                'password' => (string) $request->input('device_password', ''),
                'channel'  => $request->int('device_channel', 1) ?: 1,
            ];

            $app = [
                'name'     => $request->str('app_name', 'Шлагбаум'),
                'url'      => rtrim($request->str('app_url', $this->guessUrl($request)), '/'),
                'timezone' => $request->str('timezone', date_default_timezone_get()),
                'env'      => $request->str('env', 'production'),
                'debug'    => $request->bool('debug', false),
            ];

            // ── 2. Write the local config ─────────────────────────────────
            $key = Crypto::randomBase64(32);
            $written = $this->writeLocalConfig($app, $db, $device, $key);
            $log[] = 'Конфигурация: ' . ($written ? 'записана' : 'уже существует, обновлена');

            // Apply in-memory so the rest of the installation uses it.
            $this->config()->override([
                'app' => ['key' => $key, 'name' => $app['name'], 'url' => $app['url'], 'timezone' => $app['timezone'], 'env' => $app['env'], 'debug' => $app['debug']],
                'db'  => [
                    'default' => $db['driver'],
                    'prefix'  => $db['prefix'],
                    'connections' => [
                        $db['driver'] => array_filter([
                            'driver' => $db['driver'], 'host' => $db['host'],
                            'port' => $db['port'], 'database' => $this->absolutePath((string) $db['database']),
                            'username' => $db['username'], 'password' => $db['password'],
                            'unix_socket' => $db['socket'] ?: null,
                        ], static fn ($v) => $v !== null && $v !== ''),
                    ],
                ],
                'device' => array_filter([
                    'driver' => $device['driver'], 'base_url' => $device['base_url'] ?: null,
                    'login' => $device['login'] ?: null, 'password' => $device['password'] ?: null,
                    'lock' => ['channel' => $device['channel']],
                ], static fn ($v) => $v !== null && $v !== ''),
            ]);

            // ── 3. Verify the connection & migrate ────────────────────────
            /** @var Database $database */
            $database = $this->svc(Database::class);
            // The container built Database during middleware with the pre-install
            // config — pick up the credentials we have just written.
            $database->reload();
            if (!$database->ping()) {
                throw new \RuntimeException('Не удалось подключиться к базе данных. Проверьте параметры.');
            }
            $log[] = 'Соединение с БД: OK (' . $database->driver() . ')';

            /** @var Migrator $migrator */
            $migrator = $this->svc(Migrator::class);
            $applied = $migrator->migrate();
            $log[] = sprintf('Миграции: применено %d', count($applied));

            // ── 4. Seed ───────────────────────────────────────────────────
            /** @var SettingsService $settings */
            $settings = $this->svc(SettingsService::class);
            $settings->set('system.installed_at', date('Y-m-d H:i:s'));
            $settings->set('system.installed_version', App::VERSION);
            $settings->set('ui.organisation_name', $app['name']);
            $log[] = 'Настройки по умолчанию: записаны';

            /** @var DeviceManager $devices */
            $devices = $this->svc(DeviceManager::class);
            $devices->flush();
            if ($device['base_url'] !== '' || $device['driver'] === 'dryrun') {
                $devices->save([
                    'name' => $app['name'] . ' (основной)',
                    'slug' => 'main',
                    'driver' => $device['driver'],
                    'base_url' => $device['base_url'] ?: null,
                    'login' => $device['login'] ?: null,
                    'password' => $device['password'] !== '' ? $device['password'] : null,
                    'channel' => $device['channel'],
                    'is_active' => true,
                    'is_default' => true,
                ]);
                $log[] = 'Устройство: добавлено';
            }

            /** @var AuthService $auth */
            $auth = $this->svc(AuthService::class);
            $existing = $database->table('users')->where('role', '=', 'admin')->count();
            if ($existing === 0) {
                $errors = $auth->validatePassword($admin['password'], $admin['login']);
                if ($errors !== []) {
                    throw new \RuntimeException('Пароль администратора: ' . implode(' ', $errors));
                }
                $account = $auth->createAccount([
                    'login' => $admin['login'],
                    'email' => $admin['email'] ?: null,
                    'full_name' => $admin['full_name'],
                    'role' => 'admin',
                    'password' => $admin['password'],
                    'is_active' => true,
                    'must_change_password' => false,
                ]);
                if (!$account['ok']) {
                    throw new \RuntimeException('Не удалось создать администратора: ' . ($account['error'] ?? '?'));
                }
                $log[] = 'Администратор «' . $admin['login'] . '»: создан';
            } else {
                $log[] = 'Администратор уже существует — пропущено';
            }

            // Demo data on request (useful for a first look).
            if ($request->bool('seed_demo')) {
                $seeded = $this->seedDemo($database);
                $log[] = 'Демо-данные: ' . $seeded . ' жителей';
            }

            // ── 5. Lock ───────────────────────────────────────────────────
            Fs::ensureDir(dirname($this->lockFile()));
            Fs::put($this->lockFile(), (string) json_encode([
                'installed_at' => date(DATE_ATOM),
                'version' => App::VERSION,
                'php' => PHP_VERSION,
                'driver' => $database->driver(),
                'ip' => $request->ip($this->config()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0640);
            $log[] = 'Установка завершена, создан install.lock';

            $this->auditService()->record('system.install', null, [
                'subject_type' => 'app', 'subject_id' => App::VERSION,
                'actor_name' => $admin['login'], 'meta' => ['log' => $log], 'is_security' => true,
            ], $request);

            if ($request->expectsJson()) {
                return $this->json(['ok' => true, 'log' => $log, 'redirect' => $request->url('/login')]);
            }

            return $this->view('install/done', [
                'title' => 'Установка завершена', 'layout' => 'auth', 'log' => $log, 'admin' => $admin['login'],
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($request->expectsJson()) {
                return $this->jsonError($message, 422, ['log' => $log]);
            }

            return $this->view('install/index', [
                'title' => 'Установка BAS-IP Gate Manager', 'layout' => 'auth',
                'requirements' => $this->requirements(), 'step' => 'error',
                'error' => $message, 'log' => $log, 'old' => $request->all(),
                'localFile' => Fs::relative($this->config()->localFilePath(), $this->config()->root()),
                'version' => App::VERSION, 'suggestedAdmin' => $this->suggestAdminLogin(),
            ], 422);
        }
    }

    /** Let the operator download the generated local config file. */
    public function downloadConfig(Request $request): Response
    {
        $file = $this->config()->localFilePath();
        if (!is_file($file)) {
            return $this->jsonError('Локальный конфиг ещё не создан.', 404);
        }
        $user = $this->user();
        if ($user === null || !$user->isAdmin()) {
            return $this->jsonError('Доступно только администратору.', 403);
        }

        return Response::file($file, '.bas-ip.config.local.php');
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @return array<int, array<string, mixed>> */
    private function requirements(): array
    {
        $checks = [];
        $checks[] = ['name' => 'PHP ≥ 8.2 (рекомендуется 8.5)', 'ok' => PHP_VERSION_ID >= 80200, 'value' => PHP_VERSION . (PHP_VERSION_ID < 80500 ? ' — рекомендуется 8.5' : '')];
        foreach ([
            'curl' => 'Обмен с панелью и внешними API',
            'pdo' => 'Доступ к базе данных',
            'pdo_mysql' => 'Драйвер MySQL/MariaDB',
            'pdo_pgsql' => 'Драйвер PostgreSQL',
            'pdo_sqlite' => 'Драйвер SQLite (демо/тесты)',
            'mbstring' => 'Работа с кириллицей',
            'json' => 'JSON',
            'openssl' => 'HTTPS, шифрование',
            'sodium' => 'Шифрование секретов (AEAD)',
            'zip' => 'Резервные копии и автообновление',
            'fileinfo' => 'Проверка загружаемых файлов',
            'intl' => 'Форматирование дат и чисел (опционально)',
        ] as $ext => $why) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name' => 'ext-' . $ext,
                'ok' => $loaded || in_array($ext, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'intl', 'sodium'], true) ? $loaded : false,
                'required' => !in_array($ext, ['pdo_pgsql', 'pdo_sqlite', 'intl', 'sodium'], true),
                'value' => $loaded ? 'загружено' : 'не загружено',
                'why' => $why,
            ];
        }

        foreach (['logs' => 'logs', 'cache' => 'cache', 'sessions' => 'sessions', 'exports' => 'exports', 'backups' => 'backups', 'updates' => 'updates', 'tokens' => 'tokens'] as $label => $key) {
            $path = $this->config()->getPath($key);
            $ok = Fs::isWritable($path);
            if (!$ok) {
                @mkdir($path, 0770, true);
                $ok = Fs::isWritable($path);
            }
            $checks[] = ['name' => 'Запись: storage/' . $label, 'ok' => $ok, 'value' => Fs::relative($path, $this->config()->root())];
        }

        $localDir = dirname($this->config()->localFilePath());
        $checks[] = ['name' => 'Запись: .bas-ip.config.local.php', 'ok' => Fs::isWritable($localDir), 'value' => Fs::relative($localDir, $this->config()->root())];
        $checks[] = ['name' => 'Функция exec() (для mysqldump/автообновления)', 'ok' => function_exists('exec'), 'value' => function_exists('exec') ? 'доступна' : 'отключена', 'required' => false];

        return $checks;
    }

    /** @param array<string, mixed> $app @param array<string, mixed> $db @param array<string, mixed> $device */
    private function writeLocalConfig(array $app, array $db, array $device, string $key): bool
    {
        $file = $this->config()->localFilePath();
        $exists = is_file($file);
        if ($exists && !$this->config()->bool('installer.overwrite_local', true)) {
            return false;
        }

        $port = $db['port'] ?? ($db['driver'] === 'pgsql' ? 5432 : 3306);
        // SQLite needs exactly two keys; mysql/pgsql get the full set.
        $connectionData = $db['driver'] === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $this->absolutePath((string) $db['database'])]
            : array_filter([
                'driver'      => $db['driver'],
                'host'        => $db['host'],
                'port'        => (int) $port,
                'database'    => $db['database'],
                'username'    => $db['username'],
                'password'    => $db['password'],
                'charset'     => $db['driver'] === 'mysql' ? 'utf8mb4' : null,
                'unix_socket' => $db['socket'] !== '' ? $db['socket'] : null,
            ], static fn ($v) => $v !== null && $v !== '');
        $connection = $this->renderArray($connectionData, 12);

        $deviceBlock = $this->renderArray(array_filter([
            'driver'     => $device['driver'],
            'base_url'   => $device['base_url'] ?: null,
            'login'      => $device['login'] ?: null,
            'password'   => $device['password'] !== '' ? '${secret:DEVICE_PASSWORD}' : null,
            'verify_tls' => false,
        ], static fn ($v) => $v !== null), 4);

        $contents = <<<PHP
<?php

/**
 * BAS-IP — локальная конфигурация.
 *
 * Файл создан установщиком {$this->now()}. Он в .gitignore:
 * секреты не попадут в репозиторий и не потеряются при обновлении.
 *
 * Справка: раздел «Конфигурация» встроенной справки (/help) и wiki/03-Configuration.md
 */

declare(strict_types=1);

return [

    'app' => [
        'name'     => {$this->q($app['name'])},
        'env'      => {$this->q($app['env'])},
        'debug'    => {$this->b($app['debug'])},
        'url'      => {$this->q($app['url'])},
        'timezone' => {$this->q($app['timezone'])},
        'key'      => {$this->q($key)},
    ],

    'db' => [
        'default' => {$this->q($db['driver'])},
        'prefix'  => {$this->q((string) $db['prefix'])},
        'connections' => [
            {$this->q($db['driver'])} => $connection,
        ],
    ],

    'device' => $deviceBlock,

    'billing' => [
        'currency'        => 'RUB',
        'currency_symbol' => '₽',
        'default_total'   => null,
        'due_days'        => 14,
        'invoice_prefix'  => 'СЧ-',
        'charge_prefix'   => 'НАЧ-',
    ],

    'notify' => [
        'default' => ['log'],          // добавьте 'mail' / 'telegram' после настройки
    ],

    'update' => [
        'enabled' => true,
        'mode'    => 'notify',
        'repo'    => 'paulmann/BAS-IP',
    ],
];

PHP;

        // Store the device password as an encrypted secret instead of plaintext.
        if ($device['password'] !== '') {
            $this->config()->secrets()->set('DEVICE_PASSWORD', (string) $device['password']);
        }

        Fs::put($file, $contents, 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return !$exists;
    }

    private function seedDemo(Database $database): int
    {
        $file = $this->config()->getPath('database') . '/seeds/demo.php';
        if (!is_file($file)) {
            return 0;
        }
        /** @var callable(Database, Clock): int $seed */
        $seed = require $file;

        return is_callable($seed) ? (int) $seed($database, $this->svc(Clock::class)) : 0;
    }

    private function isLocked(): bool
    {
        return is_file($this->lockFile());
    }

    private function lockFile(): string
    {
        return $this->config()->getPath('public') . '/install.lock';
    }

    private function guessUrl(Request $request): string
    {
        $host = $request->header('host') ?? 'localhost';
        $scheme = $request->isSecure() ? 'https' : 'http';

        return $scheme . '://' . $host . $request->basePath();
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->config()->root() . '/' . ltrim($path, '/');
    }

    private function suggestAdminLogin(): string
    {
        return 'admin';
    }

    private function now(): string
    {
        return date('d.m.Y H:i');
    }

    private function q(mixed $value): string
    {
        return var_export((string) $value, true);
    }

    private function b(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Render a flat scalar array as readable short-syntax PHP, indented by
     * `$indent` spaces. Keeps the generated local config hand-editable —
     * var_export() dumps `array (…)`, which nobody wants to maintain.
     *
     * @param array<string, mixed> $data
     */
    private function renderArray(array $data, int $indent = 0): string
    {
        if ($data === []) {
            return '[]';
        }

        $pad  = str_repeat(' ', $indent);
        $width = max(array_map('strlen', array_keys($data)));
        $lines = [];
        foreach ($data as $key => $value) {
            $rendered = match (true) {
                is_bool($value)   => $value ? 'true' : 'false',
                is_int($value)    => (string) $value,
                is_float($value)  => var_export($value, true),
                $value === null   => 'null',
                is_array($value)  => $this->renderArray($value, $indent + 4),
                default           => $this->q($value),
            };
            $lines[] = $pad . '    ' . str_pad("'" . $key . "'", $width + 2) . ' => ' . $rendered . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $pad . ']';
    }
}
