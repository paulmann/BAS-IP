<?php
require __DIR__ . '/src/autoload.php';
require __DIR__ . '/tests/Support/TestCase.php';
require __DIR__ . '/tests/Support/TestApp.php';

use BasIp\Core\Session;
use BasIp\Tests\Support\TestApp;

TestApp::boot();
$admin = TestApp::makeAdmin('dbg-admin');
echo "admin id=", $admin->id, " active=", var_export($admin->isActive, true), "\n";

$session = TestApp::container()->get(Session::class);
$session->start();
$session->set('user_id', $admin->id);
echo "session user_id=", var_export($session->get('user_id'), true), "\n";

$guard = TestApp::container()->get(\BasIp\Auth\Guard::class);
$user = $guard->user();
echo "guard user=", $user === null ? 'NULL' : ($user->login . ' via=' . $guard->via()), "\n";

$response = TestApp::request('GET', '/panel', [], ['Accept' => 'text/html'], $admin);
echo "GET /panel -> ", $response->statusCode(), "\n";
foreach ($response->headers() as $k => $v) {
    if (in_array(strtolower($k), ['location', 'cache-control', 'x-frame-options'], true)) {
        echo "   $k: $v\n";
    }
}
if ($response->statusCode() !== 200) {
    echo substr(strip_tags($response->getBody()), 0, 300), "\n";
}

// Guest open
$passes = TestApp::get(\BasIp\Services\GuestPassService::class);
$rid = TestApp::makeResident(['apartment' => '77']);
$created = $passes->create(['resident_id' => $rid, 'ttl_hours' => 12, 'notify' => false]);
$r2 = TestApp::request('POST', '/guest/' . $created['token'] . '/open', [], ['Accept' => 'application/json']);
echo "guest open -> ", $r2->statusCode(), " ", substr($r2->getBody(), 0, 300), "\n";
