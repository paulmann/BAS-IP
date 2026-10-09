<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Auth\AuthService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * The web installer: pre-install connection probes, the public wizard and
 * the administrator phone number collected during setup.
 *
 * The full installation flow (config writing, migrations, lock file) is
 * covered by the E2E run on a fresh copy — it must not execute here because
 * it would overwrite the developer's local config.
 */
final class InstallTest extends TestCase
{
    protected function setUp(): void
    {
        TestApp::boot();
    }

    private function post(string $path, array $post): \BasIp\Core\Response
    {
        return TestApp::request('POST', $path, $post + ['_token' => TestApp::csrf()], ['Accept' => 'application/json']);
    }

    public function testInstallPageIsPublicBeforeLock(): void
    {
        $response = TestApp::request('GET', '/install', [], ['Accept' => 'text/html']);
        $this->assertSame(200, $response->statusCode());
        $this->assertStringContains('Установка BAS-IP Gate Manager', $response->getBody());
        $this->assertStringContains('Проверить соединение', $response->getBody());
        $this->assertStringContains('Проверить панель', $response->getBody());
        $this->assertStringContains('admin_phone', $response->getBody());
    }

    public function testTestDbAcceptsSqlite(): void
    {
        $file = sys_get_temp_dir() . '/basip-install-test-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $response = $this->post('/install/test-db', ['db_driver' => 'sqlite', 'db_database' => $file]);
        @unlink($file);

        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertTrue($data['ok'], (string) ($data['error'] ?? ''));
        $this->assertStringContains('SQLite', (string) $data['server']);
    }

    public function testTestDbRejectsUnreachableServer(): void
    {
        // Port 1 is closed on any sane machine — the probe must fail fast
        // with a human explanation, never with a stack trace.
        $response = $this->post('/install/test-db', [
            'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '1',
            'db_database' => 'nope', 'db_username' => 'nope', 'db_password' => 'nope',
        ]);

        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertFalse($data['ok']);
        $this->assertNotSame('', (string) $data['error']);
        $this->assertStringNotContains('nope', (string) $data['error']);   // credentials never echoed back
    }

    public function testTestDbRejectsUnknownDriver(): void
    {
        $data = TestApp::json($this->post('/install/test-db', ['db_driver' => 'oracle']));
        $this->assertFalse($data['ok']);
        $this->assertStringContains('Неподдерживаемый драйвер', (string) $data['error']);
    }

    public function testTestDeviceDryrunAlwaysPasses(): void
    {
        $data = TestApp::json($this->post('/install/test-device', ['device_driver' => 'dryrun']));
        $this->assertTrue($data['ok']);
        $this->assertStringContains('Эмулятор', (string) $data['message']);
    }

    public function testTestDeviceRequiresUrlAndValidatesIt(): void
    {
        $noUrl = TestApp::json($this->post('/install/test-device', ['device_driver' => 'basip', 'device_base_url' => '']));
        $this->assertFalse($noUrl['ok']);
        $this->assertStringContains('Укажите адрес', (string) $noUrl['error']);

        $badUrl = TestApp::json($this->post('/install/test-device', ['device_driver' => 'basip', 'device_base_url' => 'ftp://panel']));
        $this->assertFalse($badUrl['ok']);
        $this->assertStringContains('http', (string) $badUrl['error']);
    }

    public function testTestDeviceReportsUnreachablePanel(): void
    {
        // 127.0.0.1:1 — nothing listens there; the probe must degrade to a
        // clear «недоступна» message instead of hanging or throwing.
        $data = TestApp::json($this->post('/install/test-device', [
            'device_driver' => 'basip',
            'device_base_url' => 'http://127.0.0.1:1/api/v0/',
            'device_login' => 'admin',
            'device_password' => 'x',
        ]));
        $this->assertFalse($data['ok']);
        $this->assertStringContains('недоступна', mb_strtolower((string) $data['error']));
    }

    public function testTestEndpointsAreCsrfProtected(): void
    {
        $response = TestApp::request('POST', '/install/test-db', ['db_driver' => 'sqlite'], ['Accept' => 'application/json']);
        $this->assertSame(419, $response->statusCode());
    }

    public function testAdminAccountStoresThePhone(): void
    {
        /** @var AuthService $auth */
        $auth = TestApp::get(AuthService::class);
        $result = $auth->createAccount([
            'login' => 'install-phone-admin',
            'password' => 'Install2026!x',
            'role' => 'admin',
            'full_name' => 'Установочный Админ',
            'phone' => '8 (900) 123-45-67',
            'email' => 'install@example.com',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $user = $auth->findByLogin('install-phone-admin');
        $this->assertTrue($user !== null);
        $this->assertSame('+79001234567', $user->phone);
    }
}
