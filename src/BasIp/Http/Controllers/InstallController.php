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
        $phase = 'validate';
        $db = $app = $device = $admin = [];

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
                'phone' => $request->str('admin_phone', ''),
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
            $phase = 'write_config';
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
                            'port' => $db['port'],
                            'database' => $db['driver'] === 'sqlite'
                                ? $this->absolutePath((string) $db['database'])
                                : $db['database'],
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
            $phase = 'connect_db';
            /** @var Database $database */
            $database = $this->svc(Database::class);
            // The container built Database during middleware with the pre-install
            // config — pick up the credentials we have just written.
            $database->reload();
            try {
                $database->pdo()->query('SELECT 1');
            } catch (\Throwable $e) {
                // Surface the real driver error (humanised) — a bare «ping
                // failed» is useless both for the operator and for the report.
                $pdo = $e instanceof \PDOException ? $e : ($e->getPrevious() instanceof \PDOException ? $e->getPrevious() : null);
                throw new \RuntimeException(
                    $pdo instanceof \PDOException
                        ? $this->humanizeDbError($pdo, (string) $db['driver'], (string) $db['host'], (string) ($db['port'] ?? ''))
                        : $e->getMessage(),
                    0,
                    $e,
                );
            }
            $log[] = 'Соединение с БД: OK (' . $database->driver() . ')';

            $phase = 'migrate';
            /** @var Migrator $migrator */
            $migrator = $this->svc(Migrator::class);
            $applied = $migrator->migrate();
            $log[] = sprintf('Миграции: применено %d', count($applied));

            // ── 4. Seed ───────────────────────────────────────────────────
            $phase = 'settings';
            /** @var SettingsService $settings */
            $settings = $this->svc(SettingsService::class);
            $settings->set('system.installed_at', date('Y-m-d H:i:s'));
            $settings->set('system.installed_version', App::VERSION);
            $settings->set('ui.organisation_name', $app['name']);
            $log[] = 'Настройки по умолчанию: записаны';

            $phase = 'device';
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

            $phase = 'admin';
            /** @var AuthService $auth */
            $auth = $this->svc(AuthService::class);
            $existing = $database->table('users')->where('role', '=', 'admin')->count();
            $autoLogin = false;
            if ($existing === 0) {
                // An empty password field means «generate one for me» — the
                // generated value is shown once on the success screen.
                $generatedPassword = null;
                if ($admin['password'] === '') {
                    $generatedPassword = $auth->generatePassword(20);
                    $admin['password'] = $generatedPassword;
                }
                $errors = $auth->validatePassword($admin['password'], $admin['login']);
                if ($errors !== []) {
                    throw new \RuntimeException('Пароль администратора: ' . implode(' ', $errors));
                }
                $account = $auth->createAccount([
                    'login' => $admin['login'],
                    'email' => $admin['email'] ?: null,
                    'phone' => $admin['phone'],
                    'full_name' => $admin['full_name'],
                    'role' => 'admin',
                    'password' => $admin['password'],
                    'is_active' => true,
                    'must_change_password' => false,
                ]);
                if (!$account['ok']) {
                    throw new \RuntimeException('Не удалось создать администратора: ' . ($account['error'] ?? '?'));
                }
                $log[] = 'Администратор «' . $admin['login'] . '»: создан'
                    . ($generatedPassword !== null ? ' (пароль сгенерирован)' : '');

                // Sign the fresh admin straight in — the person who completed
                // the installation IS the administrator.
                $created = $auth->findByLogin($admin['login']);
                if ($created !== null) {
                    /** @var \BasIp\Auth\Guard $guard */
                    $guard = $this->svc(\BasIp\Auth\Guard::class);
                    $guard->login($created, false, $request);
                    $autoLogin = true;
                    $log[] = 'Выполнен вход как «' . $created->login . '»';
                }
            } else {
                $log[] = 'Администратор уже существует — пропущено';
            }

            // Demo data on request (useful for a first look).
            $phase = 'seed';
            if ($request->bool('seed_demo')) {
                $seeded = $this->seedDemo($database);
                $log[] = 'Демо-данные: ' . $seeded . ' жителей';
            }

            // ── 5. Lock ───────────────────────────────────────────────────
            $phase = 'lock';
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
                return $this->json([
                    'ok' => true, 'log' => $log,
                    'redirect' => $request->url($autoLogin ? '/admin' : '/login'),
                    'auto_login' => $autoLogin,
                ]);
            }

            return $this->view('install/done', [
                'title' => 'Установка завершена', 'layout' => 'auth', 'log' => $log, 'admin' => $admin['login'],
                'autoLogin' => $autoLogin,
                'generatedPassword' => $generatedPassword ?? null,
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $report = $this->failureReport($phase, $log, $e, $request, $db, $app, $device);

            if ($request->expectsJson()) {
                return $this->jsonError($message, 422, ['log' => $log, 'phase' => $phase, 'report' => $report]);
            }

            return $this->view('install/index', [
                'title' => 'Установка BAS-IP Gate Manager', 'layout' => 'auth',
                'requirements' => $this->requirements(), 'step' => 'error',
                'error' => $message, 'log' => $log, 'old' => $request->all(),
                'report' => $report, 'reportText' => $this->reportText($report),
                'localFile' => Fs::relative($this->config()->localFilePath(), $this->config()->root()),
                'version' => App::VERSION, 'suggestedAdmin' => $this->suggestAdminLogin(),
            ], 422);
        }
    }

    /**
     * AJAX: test the submitted database credentials BEFORE installing.
     * Connects with a throwaway PDO — nothing is written, no config touched.
     */
    public function testDb(Request $request): Response
    {
        if ($this->isLocked()) {
            return $this->jsonError('Система уже установлена.', 403);
        }

        $driver   = $request->str('db_driver', 'mysql');
        $host     = $request->str('db_host', '127.0.0.1');
        $port     = $request->int('db_port', 0);
        $database = $request->str('db_database', '');
        $username = $request->str('db_username', '');
        $password = (string) $request->input('db_password', '');
        $socket   = $request->str('db_socket', '');
        $started  = microtime(true);
        $ms       = static function () use ($started): float { return round((microtime(true) - $started) * 1000); };

        try {
            if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new \RuntimeException('Неподдерживаемый драйвер: ' . $driver);
            }

            if ($driver === 'sqlite') {
                if (!extension_loaded('pdo_sqlite')) {
                    throw new \RuntimeException('Расширение pdo_sqlite не загружено.');
                }
                $path = $this->absolutePath($database !== '' ? $database : 'storage/app/bas-ip.sqlite');
                $dir  = dirname($path);
                if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Не удалось создать каталог ' . $dir);
                }
                if (!is_writable($dir)) {
                    throw new \RuntimeException('Каталог ' . $dir . ' недоступен для записи.');
                }
                $exists  = is_file($path);
                $pdo     = new \PDO('sqlite:' . $path, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                ]);
                $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
                $pdo     = null;

                return $this->json([
                    'ok' => true, 'ms' => $ms(),
                    'server'  => 'SQLite ' . $version,
                    'message' => $exists
                        ? 'Файл существует и доступен (такая БД уже может содержать данные).'
                        : 'Файл будет создан при установке. Каталог доступен для записи.',
                    'path' => $path,
                ]);
            }

            $ext = 'pdo_' . $driver;
            if (!extension_loaded($ext)) {
                throw new \RuntimeException('Расширение ' . $ext . ' не загружено. Установите php-' . $ext . ' и перезапустите php-fpm.');
            }

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 6,
            ];

            // Step 1 — reach the SERVER (without selecting a database) so we can
            // tell «server unreachable» from «database does not exist».
            if ($driver === 'mysql') {
                $serverDsn = $socket !== ''
                    ? 'mysql:unix_socket=' . $socket . ';charset=utf8mb4'
                    : 'mysql:host=' . $host . ';port=' . ($port ?: 3306) . ';charset=utf8mb4';
            } else {
                $serverDsn = 'pgsql:host=' . $host . ';port=' . ($port ?: 5432) . ';dbname=postgres';
            }

            try {
                $pdo = new \PDO($serverDsn, $username, $password, $options);
            } catch (\PDOException $e) {
                throw new \RuntimeException($this->humanizeDbError($e, $driver, $host, (string) ($port ?: ($driver === 'pgsql' ? 5432 : 3306))));
            }

            // Step 2 — does the target database exist?
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
                $stmt->execute([$database]);
                $exists = (bool) $stmt->fetchColumn();
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            } else {
                $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
                $stmt->execute([$database]);
                $exists = (bool) $stmt->fetchColumn();
                $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
            }
            $pdo = null;

            if (!$exists) {
                return $this->json([
                    'ok' => false, 'ms' => $ms(), 'server' => $version,
                    'error' => sprintf(
                        'Подключение к серверу успешно (%s), но база «%s» не найдена. Создайте её: %s',
                        $version,
                        $database,
                        $driver === 'mysql'
                            ? 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;'
                            : 'CREATE DATABASE ' . $database . ';',
                    ),
                ]);
            }

            // Step 3 — full connection string, exactly as the app will use it.
            $dsn = $driver === 'mysql'
                ? ($socket !== ''
                    ? 'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4'
                    : 'mysql:host=' . $host . ';port=' . ($port ?: 3306) . ';dbname=' . $database . ';charset=utf8mb4')
                : 'pgsql:host=' . $host . ';port=' . ($port ?: 5432) . ';dbname=' . $database;
            $pdo = new \PDO($dsn, $username, $password, $options);
            $pdo->query('SELECT 1')->fetchColumn();
            $pdo = null;

            return $this->json([
                'ok' => true, 'ms' => $ms(), 'server' => $version,
                'message' => 'Соединение установлено, база «' . $database . '» доступна.',
            ]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'ms' => $ms(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: probe the gate panel with the submitted credentials (no state is
     * written; for BAS-IP this performs the real token handshake).
     */
    public function testDevice(Request $request): Response
    {
        if ($this->isLocked()) {
            return $this->jsonError('Система уже установлена.', 403);
        }

        $driver  = $request->str('device_driver', 'basip');
        $baseUrl = trim($request->str('device_base_url', ''));
        $login   = $request->str('device_login', '');
        $password = (string) $request->input('device_password', '');
        $started = microtime(true);
        $ms = static function () use ($started): float { return round((microtime(true) - $started) * 1000); };

        if ($driver === 'dryrun') {
            return $this->json(['ok' => true, 'ms' => $ms(), 'message' => 'Эмулятор: железо не требуется — все команды будут выполняться виртуально.']);
        }
        if ($baseUrl === '') {
            return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Укажите адрес панели (например http://192.168.1.20/api/v0/) или выберите драйвер-эмулятор.']);
        }
        if (!preg_match('~^https?://[^\s]+$~i', $baseUrl)) {
            return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Адрес должен начинаться с http:// или https://']);
        }

        /** @var \BasIp\Core\HttpClient $http */
        $http = $this->svc(\BasIp\Core\HttpClient::class);
        $options = ['timeout' => 6, 'connect_timeout' => 4, 'retries' => 0, 'accept' => 'application/json', 'verify_tls' => false];

        try {
            if ($driver === 'basip') {
                if ($login === '') {
                    return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Для проверки панели BAS-IP укажите логин.']);
                }
                // The same handshake the driver performs: GET {base}/login?username&password → token.
                $url = rtrim($baseUrl, '/') . '/login?' . http_build_query(['username' => $login, 'password' => $password]);
                $response = $http->request('GET', $url, $options);
                if ($response->status === 0) {
                    return $this->json([
                        'ok' => false, 'ms' => $ms(),
                        'error' => 'Панель недоступна по адресу ' . $baseUrl . ': ' . ($response->error ?? 'нет ответа')
                            . '. Проверьте адрес, сеть и что панель включена.',
                    ]);
                }
                if ($response->status === 401 || $response->status === 403) {
                    return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Панель ответила HTTP ' . $response->status . ' — неверный логин или пароль.']);
                }
                if ($response->status === 404) {
                    return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'HTTP 404: вероятно, адрес указан без /api/v0/ (пример: http://192.168.1.20/api/v0/).']);
                }
                if (!$response->ok()) {
                    return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Панель ответила HTTP ' . $response->status . ': ' . mb_substr(strip_tags($response->body), 0, 200)]);
                }
                $data = (array) $response->json();
                $token = null;
                foreach (['token', 'access_token', 'data.token', 'result.token'] as $path) {
                    $value = \BasIp\Support\Arr::get($data, $path);
                    if (is_string($value) && $value !== '') {
                        $token = $value;
                        break;
                    }
                }
                if ($token === null) {
                    return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Панель ответила, но не вернула токен авторизации. Проверьте, что это BAS-IP Camdroid с API /api/v0.']);
                }

                return $this->json(['ok' => true, 'ms' => $ms(), 'message' => 'Панель доступна, авторизация успешна (токен получен).']);
            }

            // http-relay: reachability probe of the base URL.
            try {
                $response = $http->request('GET', $baseUrl, $options);
            } catch (Throwable $e) {
                return $this->json(['ok' => false, 'ms' => $ms(), 'error' => 'Устройство недоступно: ' . $e->getMessage()]);
            }

            return $this->json([
                'ok' => $response->status < 500, 'ms' => $ms(),
                'message' => $response->status < 500
                    ? 'Устройство ответило HTTP ' . $response->status . ' — связь есть. Открытие проверьте после установки (gate:test).'
                    : 'Устройство ответило HTTP ' . $response->status,
            ]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'ms' => $ms(), 'error' => $e->getMessage()]);
        }
    }

    /** Turn common PDO failures into an actionable Russian message. */
    private function humanizeDbError(\PDOException $e, string $driver, string $host, string $port): string
    {
        $raw = $e->getMessage();
        $where = sprintf(' (%s://%s:%s)', $driver, $host, $port);
        $lower = mb_strtolower($raw);

        return match (true) {
            str_contains($lower, 'access denied')                      => 'Сервер отклонил учётные данные: неверный пользователь или пароль.' . $where,
            str_contains($lower, 'connection refused')                 => 'Сервер не слушает или закрыт файрволом: подключение отклонено.' . $where,
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'Сервер не ответил вовремя: проверьте адрес, порт и сетевую доступность.' . $where,
            str_contains($lower, 'unknown database')                   => 'База данных не найдена на сервере — создайте её заранее.',
            str_contains($lower, 'pg_hba.conf')                        => 'PostgreSQL не принимает такое подключение: настройте pg_hba.conf (host/пароль) на сервере.',
            str_contains($lower, 'password authentication failed')     => 'PostgreSQL отклонил пароль пользователя.',
            str_contains($lower, 'could not connect to server') || str_contains($lower, 'unable to connect') => 'Не удалось соединиться с сервером БД: проверьте хост/порт и что сервер запущен.' . $where,
            str_contains($lower, 'no such file or directory') && $driver === 'mysql' => 'Не найден unix-сокет MySQL — используйте TCP (хост/порт) или укажите верный db_socket.',
            str_contains($lower, 'ssl') || str_contains($lower, 'tls')  => 'Ошибка TLS при подключении к БД. Попробуйте отключить требование SSL или укажите корректный режим.',
            default                                                    => 'Ошибка подключения к БД: ' . $raw,
        };
    }

    /**
     * Build the machine-readable failure report shown when installation
     * breaks — designed to be pasted into an issue tracker or an LLM chat
     * for analysis. NEVER contains passwords or the app key.
     *
     * @param array<int, string> $log
     * @param array<string, mixed> $db
     * @param array<string, mixed> $app
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function failureReport(string $phase, array $log, Throwable $e, Request $request, array $db, array $app, array $device): array
    {
        $failedRequirements = [];
        foreach ($this->requirements() as $requirement) {
            if (empty($requirement['ok']) && ($requirement['required'] ?? true)) {
                $failedRequirements[] = (string) $requirement['name'];
            }
        }

        $previous = $e->getPrevious();

        return [
            'report'       => 'bas-ip-install-failure/1',
            'generated_at' => date(DATE_ATOM),
            'app'          => [
                'version' => App::VERSION,
                'phase'   => $phase,
                'php'     => PHP_VERSION,
                'sapi'    => PHP_SAPI,
                'os'      => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
                'root'    => $this->config()->root(),
                'method'  => $request->method . ' ' . $request->path,
            ],
            'submitted'    => [
                'db'     => $db === [] ? null : [
                    'driver' => $db['driver'] ?? null, 'host' => $db['host'] ?? null,
                    'port' => $db['port'] ?? null, 'database' => $db['database'] ?? null,
                    'username' => $db['username'] ?? null, 'password' => '***',
                ],
                'app'    => $app === [] ? null : ['env' => $app['env'] ?? null, 'url' => $app['url'] ?? null, 'timezone' => $app['timezone'] ?? null],
                'device' => $device === [] ? null : ['driver' => $device['driver'] ?? null, 'base_url' => $device['base_url'] ?? null, 'password' => '***'],
            ],
            'failed_requirements' => $failedRequirements,
            'steps_completed'     => $log,
            'error' => [
                'class'   => $e::class,
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'code'    => $e->getCode(),
                'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ],
            'previous' => $previous === null ? null : [
                'class'   => $previous::class,
                'message' => $previous->getMessage(),
                'file'    => $previous->getFile() . ':' . $previous->getLine(),
            ],
            'hint' => $this->failureHint($phase, $e),
        ];
    }

    private function failureHint(string $phase, Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage());

        return match ($phase) {
            'write_config' => 'Корень проекта недоступен для записи? Выполните chown -R www-data . и chmod 750, либо создайте .bas-ip.config.local.php вручную (см. example-файл).',
            'connect_db'   => str_contains($message, 'pdo')
                ? 'Не установлено расширение PDO для выбранной СУБД (php-mysql / php-pgsql / php-sqlite3).'
                : 'Проверьте хост/порт/учётные данные и что база создана. Кнопка «Проверить соединение» на шаге «База» диагностирует это до установки.',
            'migrate'      => 'Не хватает прав на DDL (CREATE/ALTER) у пользователя БД либо СУБД старше минимума (MySQL 8 / MariaDB 10.6 / PostgreSQL 13).',
            'settings'     => 'Проблема с записью в таблицу settings — проверьте, что миграции применились полностью (migrate:status).',
            'device'       => 'Устройство недоступно или отказало: установка может продолжаться с драйвером dryrun — панель можно перенастроить позже в админке.',
            'admin'        => 'Пароль не соответствует политике (минимум 10 символов, разные регистры, цифра) или логин уже занят.',
            'seed'         => 'Ошибка демо-данных — повторите установку без галки «демо-данные».',
            'lock'         => 'Каталог public/ недоступен для записи — install.lock не создан.',
            default        => 'Сверьте требования на первой вкладке мастера; приложите этот отчёт к обращению — он содержит всё для анализа.',
        };
    }

    /** Flatten the failure report into a single copy-pasteable text block. */
    private function reportText(array $report): string
    {
        $lines = [];
        $lines[] = '=== BAS-IP INSTALL FAILURE REPORT ===';
        foreach ($report['app'] as $key => $value) {
            $lines[] = sprintf('%-16s %s', $key . ':', (string) $value);
        }
        $lines[] = '';
        $lines[] = '--- submitted (passwords masked) ---';
        foreach (['db', 'app', 'device'] as $section) {
            if (!empty($report['submitted'][$section])) {
                foreach ($report['submitted'][$section] as $key => $value) {
                    $lines[] = sprintf('%s.%s = %s', $section, $key, is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        if ($report['failed_requirements'] !== []) {
            $lines[] = '';
            $lines[] = '--- failed requirements ---';
            foreach ($report['failed_requirements'] as $item) {
                $lines[] = '* ' . $item;
            }
        }
        $lines[] = '';
        $lines[] = '--- steps completed ---';
        foreach ($report['steps_completed'] as $item) {
            $lines[] = '[ok] ' . $item;
        }
        $lines[] = '';
        $lines[] = '--- error ---';
        $lines[] = 'class:   ' . $report['error']['class'];
        $lines[] = 'message: ' . $report['error']['message'];
        $lines[] = 'at:      ' . $report['error']['file'];
        if ($report['previous'] !== null) {
            $lines[] = 'previous: ' . $report['previous']['class'] . ': ' . $report['previous']['message'] . ' @ ' . $report['previous']['file'];
        }
        $lines[] = 'trace:';
        foreach ($report['error']['trace'] as $frame) {
            $lines[] = '  ' . $frame;
        }
        $lines[] = '';
        $lines[] = '--- hint ---';
        $lines[] = $report['hint'];
        $lines[] = '';
        $lines[] = '=== JSON ===';
        $lines[] = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $lines);
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
        // Absolute on POSIX (/srv/app), on a Windows drive (C:\app) and on a
        // UNC share (\\server\share) — everything else is app-root relative.
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (strlen($path) > 2
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\'));

        return $isAbsolute ? $path : $this->config()->root() . '/' . ltrim($path, '/');
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