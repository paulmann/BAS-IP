<?php
/**
 * Schema smoke test — runs every migration on a given driver.
 * Usage: php tools/migrate-smoke.php [mysql|pgsql|sqlite]
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use BasIp\Db\Migrator;
use BasIp\Db\Schema;

$driver = $argv[1] ?? 'sqlite';
$root   = dirname(__DIR__);

// Build an isolated config so the smoke test never touches real data.
$config = Config::boot($root);
$config->override([
    'db' => [
        'default' => $driver,
        'connections' => [
            'mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'basip_smoke', 'username' => 'basip', 'password' => 'basip_pass',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
                'database' => 'basip_smoke', 'username' => 'postgres', 'password' => 'postgres',
                'schema' => 'public',
            ],
            'sqlite' => [
                'driver' => 'sqlite', 'database' => sys_get_temp_dir() . '/basip-smoke-' . getmypid() . '.sqlite',
            ],
        ],
    ],
    'logging' => ['level' => 'error'],
]);

$clock  = new Clock(new DateTimeZone('UTC'));
$logger = new Logger($config, $clock);
$db     = new Database($config, $clock, $logger);
$schema = new Schema($db, $logger);
$migrator = new Migrator($db, $schema, $config, $clock, $logger);

printf("== driver: %s ==\n", $driver);
$info = $db->serverInfo();
printf("server: %s %s\n", $info['pdo_driver'] ?? '?', $info['version'] ?? '?');

$t0 = microtime(true);
$applied = $migrator->migrate();
printf("applied %d migration(s) in %.0f ms\n", count($applied), (microtime(true) - $t0) * 1000);

$tables = $db->tables();
printf("tables (%d): %s\n", count($tables), implode(', ', $tables));

$warnings = $migrator->checksumWarnings();
printf("checksum warnings: %d\n", count($warnings));

// Rollback everything and re-apply to prove `down()` works.
$rolled = $migrator->rollback(99);
printf("rolled back %d migration(s)\n", count($rolled));
$left = $db->tables();
printf("tables after rollback (%d): %s\n", count($left), implode(', ', $left) ?: '(none)');

$again = $migrator->migrate();
printf("re-applied %d migration(s)\n", count($again));

// Basic CRUD sanity check on the most complex table.
$id = $db->table('residents')->insertGetId([
    'uuid' => BasIp\Core\Crypto::uuidv4(),
    'last_name' => 'Иванов', 'first_name' => 'Иван', 'patronymic' => 'Иванович',
    'full_name' => 'Иванов Иван Иванович', 'apartment' => '12', 'apartment_no' => 12,
    'phone' => '+79161234567', 'email' => 'ivanov@example.com',
    'is_active' => 1, 'access_granted' => 1, 'is_billed' => 1,
    'share' => 1.0, 'balance' => 0.0,
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
printf("inserted resident id=%d\n", $id);

$carId = $db->table('cars')->insertGetId([
    'resident_id' => $id, 'plate' => 'А123ВС77', 'plate_normalized' => 'A123BC77',
    'brand' => 'Skoda', 'model' => 'Octavia', 'is_active' => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);
printf("inserted car id=%d\n", $carId);

$chargeId = $db->table('charges')->insertGetId([
    'uuid' => BasIp\Core\Crypto::uuidv4(), 'number' => 'CHG-TEST-1', 'title' => 'Тест',
    'total_amount' => 15000.00, 'active_count' => 3, 'per_unit_amount' => 5000.00,
    'status' => 'draft', 'created_at' => date('Y-m-d H:i:s'),
]);
printf("inserted charge id=%d\n", $chargeId);

$invId = $db->table('invoices')->insertGetId([
    'uuid' => BasIp\Core\Crypto::uuidv4(), 'number' => 'INV-TEST-1', 'charge_id' => $chargeId,
    'resident_id' => $id, 'amount' => 5000.00, 'balance' => 5000.00, 'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s'),
]);
printf("inserted invoice id=%d\n", $invId);

// upsert + aggregate + transaction
$db->transaction(function () use ($db, $invId) {
    $db->table('invoices')->where('id', '=', $invId)->update(['status' => 'paid', 'paid_amount' => 5000.00, 'balance' => 0.0]);
});
$paid = $db->table('invoices')->where('status', '=', 'paid')->sum('paid_amount');
printf("paid sum = %s\n", $paid);

$db->table('settings')->upsert(['key' => 'smoke', 'value' => '1', 'type' => 'string', 'group' => 'test', 'updated_at' => date('Y-m-d H:i:s')], ['key']);
$db->table('settings')->upsert(['key' => 'smoke', 'value' => '2', 'type' => 'string', 'group' => 'test', 'updated_at' => date('Y-m-d H:i:s')], ['key']);
printf("upsert settings -> %s (rows=%d)\n", var_export($db->table('settings')->where('key', '=', 'smoke')->value('value'), true), $db->table('settings')->count());

$cascade = $db->table('residents')->where('id', '=', $id)->delete();
printf("deleted resident, remaining cars=%d invoices=%d\n", $db->table('cars')->count(), $db->table('invoices')->count());

echo "OK\n";
