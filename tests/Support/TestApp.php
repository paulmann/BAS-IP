<?php

declare(strict_types=1);

namespace BasIp\Tests\Support;

use BasIp\Auth\AuthService;
use BasIp\Auth\User;
use BasIp\Core\App;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Db\Database;
use BasIp\Db\Migrator;
use Throwable;

/**
 * Boots a throwaway application instance for tests.
 *
 * Each test gets its own SQLite file (or the shared MySQL/PostgreSQL database
 * when `BASIP_TEST_DB` is set), fully migrated and optionally seeded, so tests
 * never touch real data and can run in any order.
 */
final class TestApp
{
    private static ?App $app = null;
    private static ?string $dbFile = null;

    public static function boot(bool $seed = false, array $overrides = []): App
    {
        if (self::$app !== null) {
            return self::$app;
        }

        $root = dirname(__DIR__, 2);
        $driver = getenv('BASIP_TEST_DB') ?: 'sqlite';

        if ($driver === 'sqlite') {
            self::$dbFile = sys_get_temp_dir() . '/basip-test-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
            $connection = ['driver' => 'sqlite', 'database' => self::$dbFile];
        } elseif ($driver === 'pgsql') {
            $connection = [
                'driver' => 'pgsql', 'host' => getenv('BASIP_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BASIP_TEST_DB_PORT') ?: 5432),
                'database' => getenv('BASIP_TEST_DB_NAME') ?: 'basip_test',
                'username' => getenv('BASIP_TEST_DB_USER') ?: 'postgres',
                'password' => getenv('BASIP_TEST_DB_PASSWORD') ?: 'postgres',
                'schema' => 'public',
            ];
        } else {
            $connection = [
                'driver' => 'mysql', 'host' => getenv('BASIP_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BASIP_TEST_DB_PORT') ?: 3306),
                'database' => getenv('BASIP_TEST_DB_NAME') ?: 'basip_test',
                'username' => getenv('BASIP_TEST_DB_USER') ?: 'basip',
                'password' => getenv('BASIP_TEST_DB_PASSWORD') ?: 'basip_pass',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ];
        }

        /** @var App $app */
        $app = require $root . '/src/bootstrap.php';
        $app->config()->override(array_replace_recursive([
            'app' => [
                'key' => base64_encode(random_bytes(32)),
                'env' => 'testing',
                'debug' => true,
                'url' => 'http://localhost',
                'timezone' => 'Europe/Moscow',
                'name' => 'BAS-IP Test',
            ],
            'db' => ['default' => $driver, 'connections' => [$driver => $connection]],
            'device' => ['driver' => 'dryrun', 'base_url' => null],
            'logging' => ['level' => 'critical', 'stderr' => false],
            'auth' => ['session' => ['secure' => false]],
            'notify' => ['default' => ['log'], 'queue' => ['enabled' => true, 'throttle_ms' => 0]],
            'update' => ['enabled' => false],
            'paths' => ['sessions' => sys_get_temp_dir() . '/basip-test-sessions-' . getmypid()],
        ], $overrides));

        self::$app = $app;

        // Clean slate.
        $db = self::db();
        if ($db->isMySql()) {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (array_reverse($db->tables()) as $table) {
            $db->statement('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table) . ($db->isPgsql() ? ' CASCADE' : ''));
        }
        if ($db->isMySql()) {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        self::container()->get(Migrator::class)->migrate();

        if ($seed) {
            $seeder = require $root . '/database/seeds/demo.php';
            $seeder($db, self::container()->get(Clock::class), 24);
        }

        return $app;
    }

    public static function app(): App
    {
        return self::$app ?? self::boot();
    }

    public static function container(): Container
    {
        return self::app()->container();
    }

    public static function config(): Config
    {
        return self::container()->get(Config::class);
    }

    public static function db(): Database
    {
        return self::container()->get(Database::class);
    }

    public static function clock(): Clock
    {
        return self::container()->get(Clock::class);
    }

    /** @template T @param class-string<T> $id @return T */
    public static function get(string $id): mixed
    {
        return self::container()->get($id);
    }

    public static function shutdown(): void
    {
        self::$app = null;
        if (self::$dbFile !== null && is_file(self::$dbFile)) {
            @unlink(self::$dbFile);
            @unlink(self::$dbFile . '-wal');
            @unlink(self::$dbFile . '-shm');
            self::$dbFile = null;
        }
    }

    /* ─────────────────────────── fixtures ─────────────────────────── */

    public static function makeAdmin(string $login = 'admin', string $password = 'TestPass2026!'): User
    {
        return self::makeUser($login, $password, 'admin');
    }

    public static function makeOperator(string $login = 'operator', string $password = 'TestPass2026!'): User
    {
        return self::makeUser($login, $password, 'operator');
    }

    /**
     * Create (or reuse) an account. Tests share one database, so a repeated
     * login is not an error — the existing row is reactivated instead.
     */
    public static function makeUser(string $login, string $password, string $role, ?int $residentId = null): User
    {
        /** @var AuthService $auth */
        $auth = self::get(AuthService::class);

        $existing = $auth->findByLogin($login);
        if ($existing === null) {
            $result = $auth->createAccount([
                'login' => $login, 'password' => $password, 'role' => $role,
                'full_name' => ucfirst($login), 'resident_id' => $residentId,
                'is_active' => true, 'must_change_password' => false,
            ]);
            if (!$result['ok']) {
                throw new \RuntimeException('Cannot create test user: ' . ($result['error'] ?? '?'));
            }
        } else {
            self::db()->table('users')->where('id', '=', $existing->id)->update([
                'is_active' => 1, 'deleted_at' => null, 'locked_until' => null, 'failed_attempts' => 0,
            ]);
        }

        $user = $auth->findByLogin($login);
        if ($user === null) {
            throw new \RuntimeException('Test user disappeared: ' . $login);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    public static function makeResident(array $attributes = []): int
    {
        static $sequence = 0;
        $sequence++;
        $now = date('Y-m-d H:i:s');
        $apartment = (string) ($attributes['apartment'] ?? $sequence);

        return self::db()->table('residents')->insertGetId(array_merge([
            'uuid' => \BasIp\Core\Crypto::uuidv4(),
            'last_name' => 'Тестов', 'first_name' => 'Тест', 'patronymic' => 'Тестович',
            'full_name' => 'Тестов Тест Тестович',
            'search_index' => 'тестов тест тестович кв ' . $apartment,
            'apartment' => $apartment, 'apartment_no' => (int) preg_replace('/\D/', '', $apartment) ?: $sequence,
            'building' => '1', 'area' => 50.0, 'persons' => 2, 'share' => 1.0,
            'role_in_home' => 'owner',
            'phone' => '+7916123' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'email' => 'test' . $sequence . '@example.com',
            'access_granted' => 1, 'is_active' => 1, 'is_billed' => 1,
            'allow_guest_pass' => 1, 'notify_enabled' => 1,
            'balance' => 0.0,
            'created_at' => $now, 'updated_at' => $now,
        ], $attributes));
    }

    /* ─────────────────────────── HTTP helper ─────────────────────────── */

    /**
     * Dispatch a request through the full stack (middleware → router → view).
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $path, array $post = [], array $headers = [], ?User $as = null): Response
    {
        $app = self::app();

        if ($as !== null) {
            // Authenticate without touching PHP sessions: put the user id in
            // the session store the guard reads from.
            $session = self::container()->get(\BasIp\Core\Session::class);
            $session->start();
            $session->set('user_id', $as->id);
            $session->set('user_role', $as->role);
        }

        $request = Request::fake($method, $path, $post, [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_HOST' => 'localhost',
        ], [], [], '', array_merge(['Accept' => 'application/json'], $headers));

        try {
            return $app->handle($request);
        } catch (Throwable $e) {
            return $app->renderThrowable($e, $request);
        }
    }

    public static function csrf(): string
    {
        return self::container()->get(\BasIp\Core\Csrf::class)->token();
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $data = json_decode($response->getBody(), true);

        return is_array($data) ? $data : [];
    }
}
