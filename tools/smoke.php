<?php
/**
 * End-to-end smoke test: boot the app on SQLite, migrate, seed, log in,
 * then dispatch every significant route and report status codes.
 *
 * Usage: php tools/smoke.php [mysql|pgsql|sqlite]
 */
declare(strict_types=1);

use BasIp\Auth\AuthService;
use BasIp\Auth\Guard;
use BasIp\Core\App;
use BasIp\Core\Config;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Core\Session;
use BasIp\Db\Database;
use BasIp\Db\Migrator;

require_once __DIR__ . '/../src/autoload.php';

$driver = $argv[1] ?? 'sqlite';
$root   = dirname(__DIR__);
$dbFile = sys_get_temp_dir() . '/basip-smoke-' . getmypid() . '.sqlite';
@unlink($dbFile);

/** @var App $app */
$app = require $root . '/src/bootstrap.php';
$config = $app->config();

$config->override([
    'app' => [
        'key' => base64_encode(random_bytes(32)),
        'env' => 'testing',
        'debug' => true,
        'url' => 'http://localhost',
        'timezone' => 'Europe/Moscow',
    ],
    'db' => [
        'default' => $driver,
        'connections' => [
            'sqlite' => ['driver' => 'sqlite', 'database' => $dbFile],
            'mysql'  => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                         'database' => 'basip_smoke', 'username' => 'basip', 'password' => 'basip_pass',
                         'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'pgsql'  => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
                         'database' => 'basip_smoke', 'username' => 'postgres', 'password' => 'postgres', 'schema' => 'public'],
        ],
    ],
    'paths' => ['sessions' => sys_get_temp_dir() . '/basip-sess-' . getmypid()],
    'device' => ['driver' => 'dryrun', 'base_url' => null],
    'logging' => ['level' => 'error'],
    'auth' => ['session' => ['secure' => false]],
]);

$container = $app->container();
$failures = 0;
$checks = 0;

function out(string $msg): void { echo $msg, "\n"; }
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; }
    printf("  [%s] %-52s %s\n", $ok ? ' OK ' : 'FAIL', $label, $detail);
}

out('== BAS-IP smoke test · driver: ' . $driver . ' ==');

// ── 1. schema ────────────────────────────────────────────────────────────
$db = $container->get(Database::class);
$migrator = $container->get(Migrator::class);

// Reset to a clean slate so the run is repeatable on MySQL/PostgreSQL.
if ($db->isMySql()) {
    $db->statement('SET FOREIGN_KEY_CHECKS = 0');
}
foreach (array_reverse($db->tables()) as $table) {
    $db->statement('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table)
        . ($db->isPgsql() ? ' CASCADE' : ''));
}
if ($db->isMySql()) {
    $db->statement('SET FOREIGN_KEY_CHECKS = 1');
}

$applied = $migrator->migrate();
$migrationFiles = count(glob($root . '/database/migrations/*.php') ?: []);
check('migrations', count($applied) === $migrationFiles, count($applied) . ' applied of ' . $migrationFiles . ', ' . count($db->tables()) . ' tables');

// ── 2. seed ──────────────────────────────────────────────────────────────
$seedFile = $root . '/database/seeds/demo.php';
$seeder = require $seedFile;
$count = (int) $seeder($db, $container->get(BasIp\Core\Clock::class), 24);
check('demo seed', $count === 24, $count . ' residents');

// ── 3. admin account ─────────────────────────────────────────────────────
$auth = $container->get(AuthService::class);
$account = $auth->createAccount([
    'login' => 'admin', 'password' => 'SmokeTest2026!', 'role' => 'admin',
    'full_name' => 'Smoke Admin', 'email' => 'admin@example.com',
    'is_active' => true, 'must_change_password' => false,
]);
check('admin account', (bool) $account['ok'], (string) ($account['error'] ?? 'id=' . ($account['id'] ?? '?')));

$operator = $auth->createAccount(['login' => 'dispatcher', 'password' => 'SmokeTest2026!', 'role' => 'operator', 'full_name' => 'Dispatcher']);
$residentUser = $auth->createAccount(['login' => 'resident', 'password' => 'SmokeTest2026!', 'role' => 'resident', 'full_name' => 'Resident', 'resident_id' => 1]);
check('operator + resident accounts', (bool) ($operator['ok'] ?? false) && (bool) ($residentUser['ok'] ?? false));

// ── 4. core services ─────────────────────────────────────────────────────
$billing = $container->get(BasIp\Services\BillingService::class);
$preview = $billing->preview(15000.0, 'equal');
check('billing preview', $preview['participants'] > 0 && abs($preview['sum'] - 15000.0) < 0.01,
    sprintf('%d participants, per_unit=%.2f, sum=%.2f, delta=%.2f', $preview['participants'], $preview['per_unit'], $preview['sum'], $preview['rounding_delta']));

$charge = $billing->createCharge(['total_amount' => 15000, 'title' => 'Smoke', 'issue' => true]);
check('create charge', (bool) ($charge['ok'] ?? false), (string) ($charge['number'] ?? $charge['error'] ?? ''));
$chargeId = (int) ($charge['id'] ?? 0);

$stats0 = $billing->chargeStats($chargeId);
check('invoices generated', $stats0['count'] === $preview['participants'], $stats0['count'] . ' invoices, sum ' . $stats0['amount']);

// payment
$firstInvoice = $db->table('invoices')->where('charge_id', '=', $chargeId)->orderBy('id')->first();
$firstResident = (int) $firstInvoice['resident_id'];
$amount = (float) $firstInvoice['amount'];
// Pay the whole balance of that invoice explicitly (FIFO could otherwise pick
// an older demo invoice of the same resident).
$pay = $billing->recordPayment(['resident_id' => $firstResident, 'amount' => $amount, 'invoice_id' => (int) $firstInvoice['id'], 'method' => 'cash']);
check('record payment', (bool) ($pay['ok'] ?? false), json_encode($pay['allocated'] ?? [], JSON_UNESCAPED_UNICODE));
$paidInvoice = $db->table('invoices')->where('id', '=', (int) $firstInvoice['id'])->first();
check('invoice marked paid', ($paidInvoice['status'] ?? '') === 'paid', (string) $paidInvoice['status']);

// FIFO allocation: an untargeted payment must settle the oldest unpaid invoice.
$secondResident = (int) $db->table('invoices')->where('charge_id', '=', $chargeId)->where('resident_id', '!=', $firstResident)->orderBy('id')->value('resident_id');
$secondAmount = (float) $db->table('invoices')->where('charge_id', '=', $chargeId)->where('resident_id', '=', $secondResident)->orderBy('due_date')->value('amount');
$fifo = $billing->recordPayment(['resident_id' => $secondResident, 'amount' => $secondAmount, 'method' => 'transfer']);
check('FIFO allocation without invoice_id', (bool) ($fifo['ok'] ?? false) && count($fifo['allocated'] ?? []) >= 1, json_encode($fifo['allocated'] ?? [], JSON_UNESCAPED_UNICODE));

// recalculation: change the total and add a participant
$db->table('residents')->where('is_active', '=', 0)->limit(1)->update(['is_active' => 1, 'is_billed' => 1]);
$recalc = $billing->recalculate($chargeId, ['total_amount' => 18000], null, null, true);
check('recalc dry-run', (bool) ($recalc['ok'] ?? false), sprintf('adj=%d new=%d credits=%d per_unit=%.2f', $recalc['adjustments'] ?? 0, $recalc['new_invoices'] ?? 0, $recalc['credits'] ?? 0, $recalc['per_unit'] ?? 0));
$recalcApplied = $billing->recalculate($chargeId, ['total_amount' => 18000]);
check('recalc applied', (bool) ($recalcApplied['ok'] ?? false), 'adjustments=' . ($recalcApplied['adjustments'] ?? 0));
$chargeAfter = $billing->charge($chargeId);
check('charge total updated', abs($chargeAfter->totalAmount - 18000.0) < 0.01, (string) $chargeAfter->totalAmount);
$adjustments = $db->table('invoices')->where('charge_id', '=', $chargeId)->where('kind', '=', 'adjustment')->count();
check('adjustment invoices created', $adjustments > 0, $adjustments . ' rows');
$original = $db->table('invoices')->where('charge_id', '=', $chargeId)->where('kind', '=', 'regular')->orderBy('id')->first();
check('original invoice untouched', abs((float) $original['amount'] - $amount) < 0.001, (string) $original['amount']);

// notify (log channel → outbox rows)
$notify = $billing->notifyCharge($chargeId, [], 'payment_new');
check('charge notification queued', $notify['queued'] > 0, 'queued=' . $notify['queued'] . ' skipped=' . $notify['skipped']);
$worker = $container->get(BasIp\Notify\QueueWorker::class);
$queueStats = $worker->run(50, 20);
check('queue processed', $queueStats['processed'] > 0 && $queueStats['failed'] === 0, json_encode($queueStats));

// ── 5. gate ──────────────────────────────────────────────────────────────
$gate = $container->get(BasIp\Services\GateService::class);
$resident = $container->get(BasIp\Domain\Repository\ResidentRepository::class)->findById($firstResident);
$open = $gate->open(['resident' => $resident, 'access_type' => 'own', 'source' => 'cli']);
check('gate open (dryrun)', $open->ok, $open->message);
$eventCount = $db->table('gate_events')->count();
check('gate event logged', $eventCount > 0, $eventCount . ' events');

// guest pass
$guests = $container->get(BasIp\Services\GuestPassService::class);
$pass = $guests->create(['resident_id' => $firstResident, 'ttl_hours' => 12, 'guest_name' => 'Курьер', 'notify' => false]);
check('guest pass created', (bool) ($pass['ok'] ?? false), (string) ($pass['code'] ?? $pass['error'] ?? ''));
$passId = (int) ($pass['id'] ?? 0);
$found = $guests->findByToken((string) ($pass['token'] ?? ''));
check('guest pass resolvable by token', $found !== null && $found->isActive(), (string) ($found?->code ?? 'not found'));
// Cooldown protects the relay; the guest opening must not be blocked by the
// owner's own open a moment earlier, so give it a distinct resident context.
$container->get(BasIp\Core\Cache::class)->flush();
$guestOpen = $gate->open(['guest_pass' => $found, 'resident' => $resident, 'access_type' => 'guest', 'source' => 'web']);
check('gate open by guest pass', $guestOpen->ok, $guestOpen->message);
$guestEvent = $db->table('gate_events')->where('access_type', '=', 'guest')->orderBy('id', 'desc')->first();
check('guest event has access_type=guest', ($guestEvent['access_type'] ?? '') === 'guest', (string) ($guestEvent['reason'] ?? ''));
$guests->revoke($passId, 'smoke');
$revoked = $guests->find($passId);
check('guest pass revoked', $revoked !== null && !$revoked->isActive(), (string) $revoked->status);

// denial path
$db->table('residents')->where('id', '=', $firstResident)->update(['access_granted' => 0]);
$denied = $gate->open(['resident' => $container->get(BasIp\Domain\Repository\ResidentRepository::class)->findById($firstResident), 'access_type' => 'own', 'source' => 'web']);
check('denied when access revoked', !$denied->ok && $denied->errorCode === 'no_access', $denied->message);
$deniedLogged = $db->table('gate_events')->where('result', '=', 'denied')->count();
check('denial logged', $deniedLogged > 0, $deniedLogged . ' denied rows');
$db->table('residents')->where('id', '=', $firstResident)->update(['access_granted' => 1]);

// ── 6. import/export ─────────────────────────────────────────────────────
$io = $container->get(BasIp\Services\ImportExportService::class);
$export = $io->exportResidents(['status' => 'all'], ['format' => 'csv', 'scope' => 'all', 'include' => ['contacts', 'cars', 'payments']]);
check('csv export', $export['rows'] > 0 && is_file($export['path']), $export['rows'] . ' rows, ' . $export['bytes'] . ' bytes');
$dry = $io->importResidents((string) file_get_contents($export['path']), 'csv', ['mode' => 'dry-run', 'delimiter' => ';']);
check('csv import dry-run', (bool) ($dry['ok'] ?? false), sprintf('total=%d created=%d updated=%d failed=%d', $dry['total'] ?? 0, $dry['imported'] ?? 0, $dry['updated'] ?? 0, $dry['failed'] ?? 0));
$jsonExport = $io->exportResidents(['status' => 'all'], ['format' => 'json', 'scope' => 'all', 'include' => ['contacts', 'cars', 'payments', 'invoices']]);
check('json export', is_file($jsonExport['path']), $jsonExport['bytes'] . ' bytes');

// ── 7. backup ────────────────────────────────────────────────────────────
$backups = $container->get(BasIp\Services\BackupService::class);
$backup = $backups->create('db', 'smoke');
check('backup create', (bool) ($backup['ok'] ?? false), (string) ($backup['file'] ?? $backup['error'] ?? ''));

// ── 8. health & doctor ───────────────────────────────────────────────────
$data = $container->get(BasIp\Services\DataService::class);
$health = $data->health(true);
$critical = ['database', 'schema', 'app_key'];
$criticalOk = true;
foreach ($critical as $name) { if (empty($health['checks'][$name]['ok'])) { $criticalOk = false; } }
check('health: critical checks pass', $criticalOk, $health['status'] . ' · ' . count($health['checks']) . ' checks · php=' . ($health['checks']['php']['value'] ?? '?'));
check('health: device reachable (dryrun)', !empty($health['checks']['device']['ok']), (string) ($health['checks']['device']['message'] ?? 'n/a'));
$issues = $data->doctor();
$errors = array_values(array_filter($issues, static fn ($i) => $i['level'] === 'error'));
// PHP 8.5 is not available in every environment; ignore the version notice itself.
$errors = array_values(array_filter($errors, static fn ($i) => !str_contains($i['message'], 'Установлен PHP')));
check('doctor: no critical errors', $errors === [], $errors === [] ? count($issues) . ' notes' : json_encode(array_column($errors, 'message'), JSON_UNESCAPED_UNICODE));

// ── 9. HTTP routes ───────────────────────────────────────────────────────
out("\n-- HTTP routes --");

function request(App $app, string $method, string $path, array $post = [], array $headers = [], ?string $body = null): Response {
    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $path,
        'HTTP_HOST' => 'localhost',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'SCRIPT_NAME' => '/index.php',
        'PHP_SELF' => '/index.php',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    foreach ($headers as $k => $v) { $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v; }
    $request = Request::fake($method, $path, $post, $server, [], [], $body ?? '', $headers);
    try {
        return $app->handle($request);
    } catch (Throwable $e) {
        return $app->renderThrowable($e, $request);
    }
}

// Public routes
$publicRoutes = [
    ['GET', '/health'], ['GET', '/health.json'], ['GET', '/metrics'], ['GET', '/version'],
    ['GET', '/manifest.webmanifest'], ['GET', '/login'], ['GET', '/guest'],
    ['GET', '/bas-ip.php'], ['GET', '/api/v1/meta'], ['GET', '/api/v1/docs'],
    ['GET', '/nonexistent-page'],
];
foreach ($publicRoutes as [$method, $path]) {
    $response = request($app, $method, $path);
    // 404 for unknown pages, 301 for the v1.x shims, 200 otherwise.
    $expected = match (true) {
        $path === '/nonexistent-page', $path === '/help/nonexistent-section' => [404],
        str_ends_with($path, '.php')  => [301, 302, 200],
        str_starts_with($path, '/health') => [200, 503],   // 503 when the environment is degraded
        default => [200],
    };
    check("$method $path", in_array($response->statusCode(), $expected, true), 'HTTP ' . $response->statusCode() . ' · ' . strlen($response->getBody()) . ' bytes');
}

// Login flow
$session = $container->get(Session::class);
$session->start();
$loginResult = $auth->attempt('admin', 'SmokeTest2026!', null, false);
check('admin login', (bool) ($loginResult['ok'] ?? false), (string) ($loginResult['reason'] ?? 'ok'));

// Authenticated routes (session-based)
$authedRoutes = [
    '/dashboard', '/panel', '/panel/status', '/profile', '/profile/sessions', '/profile/2fa',
    '/help', '/help/quick-start', '/help/billing-charges', '/help/guest-access', '/help/api',
    '/help/nonexistent-section',   // 404 expected
    '/admin', '/admin/residents', '/admin/residents/create', '/admin/residents/1', '/admin/residents/1/edit',
    '/admin/billing', '/admin/billing/wizard', '/admin/billing/invoices', '/admin/billing/payments', '/admin/billing/debtors',
    '/admin/billing/charges/' . $chargeId,
    '/admin/gate', '/admin/gate/stats', '/admin/gate/devices', '/admin/gate/schedules',
    '/admin/guest', '/admin/users', '/admin/users/1', '/admin/users/sessions/all', '/admin/users/logins',
    '/admin/notifications', '/admin/notifications/batches/1', '/admin/notifications/templates',
    '/admin/data', '/admin/settings', '/admin/settings/billing', '/admin/settings/system',
    '/admin/system', '/admin/system/doctor', '/admin/system/logs', '/admin/system/logs/gate',
    '/admin/audit', '/admin/api-tokens', '/admin/announcements', '/admin/updates',
    '/cabinet', '/cabinet/guest', '/cabinet/payments', '/cabinet/log', '/cabinet/profile',
];
$statusCounts = [];
$expected404 = ['/help/nonexistent-section'];
foreach ($authedRoutes as $path) {
    $response = request($app, 'GET', $path);
    $code = $response->statusCode();
    $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
    $ok = in_array($code, [200, 302], true) || (in_array($path, $expected404, true) && $code === 404);
    if (!$ok) {
        check("GET $path", false, 'HTTP ' . $code . ' · ' . substr(strip_tags($response->getBody()), 0, 200));
    }
}
$unexpected404 = ($statusCounts[404] ?? 0) - count($expected404);
check('all authenticated GET routes', ($statusCounts[500] ?? 0) === 0 && $unexpected404 <= 0,
    json_encode($statusCounts) . ' over ' . count($authedRoutes) . ' routes');

// POST actions
$token = $container->get(BasIp\Core\Csrf::class)->token();
$postRoutes = [
    ['/panel/open', ['_token' => $token]],
    ['/admin/gate/open', ['_token' => $token]],
    ['/admin/billing/preview', ['_token' => $token, 'total_amount' => '15000']],
    ['/admin/residents/1/toggle', ['_token' => $token, 'field' => 'is_active', 'value' => '1']],
    ['/admin/billing/payments', ['_token' => $token, 'resident_id' => (string) $firstResident, 'amount' => '100', 'method' => 'cash', 'redirect' => '/admin/billing']],
];
foreach ($postRoutes as [$path, $payload]) {
    $response = request($app, 'POST', $path, $payload, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
    check("POST $path", $response->statusCode() < 500, 'HTTP ' . $response->statusCode() . ' · ' . substr($response->getBody(), 0, 140));
}

// CSRF protection must reject a tokenless POST
$noCsrf = request($app, 'POST', '/panel/open', [], ['Accept' => 'application/json']);
check('CSRF rejects tokenless POST', $noCsrf->statusCode() === 419 || $noCsrf->statusCode() === 302, 'HTTP ' . $noCsrf->statusCode());

// Guest page with a real token
$newPass = $guests->create(['resident_id' => $firstResident, 'ttl_hours' => 12, 'guest_name' => 'Гость', 'notify' => false]);
$guestPage = request($app, 'GET', '/guest/' . rawurlencode((string) $newPass['token']));
check('GET /guest/{token}', $guestPage->statusCode() === 200 && str_contains($guestPage->getBody(), (string) $newPass['code']), 'HTTP ' . $guestPage->statusCode());
$guestBad = request($app, 'GET', '/guest/invalidtoken123');
check('GET /guest/invalid → 404', $guestBad->statusCode() === 404, 'HTTP ' . $guestBad->statusCode());

// API with a token
$tokens = $container->get(BasIp\Services\ApiTokenService::class);
$apiToken = $tokens->create('smoke', ['*'], null, null);
$apiHeaders = ['Authorization' => 'Bearer ' . $apiToken['token'], 'Accept' => 'application/json'];
foreach ([['GET', '/api/v1/gate/status'], ['GET', '/api/v1/gate/events'], ['GET', '/api/v1/residents'], ['GET', '/api/v1/stats'], ['POST', '/api/v1/gate/open'], ['POST', '/api/v1/guest/passes']] as [$m, $p]) {
    $payload = $m === 'POST' ? ['resident_id' => $firstResident, 'ttl_hours' => 6] : [];
    $response = request($app, $m, $p, $payload, $apiHeaders);
    check("API $m $p", $response->statusCode() < 500, 'HTTP ' . $response->statusCode() . ' · ' . substr($response->getBody(), 0, 110));
}
$noAuth = request($app, 'GET', '/api/v1/gate/status', [], ['Accept' => 'application/json']);
check('API without token → 401', $noAuth->statusCode() === 401, 'HTTP ' . $noAuth->statusCode());

// ── 10. view rendering sanity ────────────────────────────────────────────
out("\n-- content checks --");
$panel = request($app, 'GET', '/panel');
check('panel has gate button', str_contains($panel->getBody(), 'data-gate-open'), '');
check('panel includes local css', str_contains($panel->getBody(), '/assets/css/app.css'), '');
check('panel has no CDN links', !preg_match('~https?://(?!localhost|gate\.example|pay\.example|github\.com|www\.php\.net|api\.telegram|developers\.bas-ip)[a-z0-9.-]+\.(com|net|org|io)/[^"\']*\.js~i', $panel->getBody()), '');
// /login redirects to the dashboard once authenticated — check both cases.
$login = request($app, 'GET', '/login');
check('login page renders (or redirects when authed)', $login->statusCode() === 302 || str_contains($login->getBody(), 'Вход в систему'), 'HTTP ' . $login->statusCode());
$residents = request($app, 'GET', '/admin/residents');
check('residents table renders rows', substr_count($residents->getBody(), 'data-row-select') > 5, substr_count($residents->getBody(), 'data-row-select') . ' rows');
$wizard = request($app, 'GET', '/admin/billing/wizard');
check('billing wizard renders', str_contains($wizard->getBody(), 'data-charge-wizard'), '');
$help = request($app, 'GET', '/help');
check('help index renders sections', substr_count($help->getBody(), '/help/') > 20, substr_count($help->getBody(), '/help/') . ' links');

// ── summary ──────────────────────────────────────────────────────────────
out(sprintf("\n== %d checks, %d failures ==\n", $checks, $failures));
@unlink($dbFile);
exit($failures > 0 ? 1 : 0);
