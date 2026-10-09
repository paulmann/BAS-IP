<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Auth\AuthService;
use BasIp\Services\UserService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * The resident gate remote: phone-number login, auto-provisioned accounts,
 * the full-screen /remote page, the camera list bound to gates and the
 * per-device camera settings (admin form → options → CSP).
 */
final class RemoteTest extends TestCase
{
    protected function setUp(): void
    {
        TestApp::boot();
        TestApp::db()->table('devices')->where('id', '>', 0)->delete();
        TestApp::db()->table('residents')->where('id', '>', 0)->delete();
        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        TestApp::get(\BasIp\Device\DeviceManager::class)->flush();
    }

    /* ─────────────────────── phone-number login ─────────────────────── */

    public function testLoginByPhoneInEveryHumanFormat(): void
    {
        /** @var AuthService $auth */
        $auth = TestApp::get(AuthService::class);
        $created = $auth->createAccount([
            'login' => 'phoneuser', 'password' => 'Phone2026!x', 'role' => 'resident',
            'phone' => '+79991234567', 'full_name' => 'Телефонов Т.Т.',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $this->assertTrue($created['ok'], (string) ($created['error'] ?? ''));

        foreach (['+79991234567', '8 (999) 123-45-67', '79991234567', '89991234567', '9991234567'] as $identifier) {
            $result = $auth->attempt($identifier, 'Phone2026!x');
            $this->assertTrue($result['ok'] ?? false, '«' . $identifier . '» must sign the user in');
        }

        // A wrong number / wrong password still fails.
        $this->assertFalse($auth->attempt('+79990000000', 'Phone2026!x')['ok'] ?? false);
        $this->assertFalse($auth->attempt('+79991234567', 'WrongPass1!')['ok'] ?? false);
    }

    public function testResidentAccountIsProvisionedWithPhoneLoginAndGeneratedPassword(): void
    {
        $residentId = TestApp::makeResident([
            'apartment' => '55',
            'phone' => '+79165554433',
            'email' => 'p55@example.com',
        ]);

        /** @var UserService $users */
        $users = TestApp::get(UserService::class);
        $result = $users->ensureResidentAccount($residentId);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame('79165554433', $result['login'], 'the phone digits become the login');
        $this->assertTrue(strlen((string) $result['password']) >= 12, 'a password is generated and returned once');

        // Signing in with the human-written phone works…
        $auth = TestApp::get(AuthService::class);
        $this->assertTrue($auth->attempt('8 (916) 555-44-33', (string) $result['password'])['ok']);

        $user = $auth->findByLogin('+79165554433');
        $this->assertTrue($user !== null);
        $this->assertSame($residentId, $user->residentId);
        $this->assertSame('+79165554433', $user->phone);
    }

    /* ─────────────────────── the remote page ─────────────────────── */

    public function testRemotePageIsResidentReachableAndGiant(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '56']);
        $resident = TestApp::makeUser('remote-res', 'Pass2026!x', 'resident', $residentId);

        $response = TestApp::request('GET', '/remote', [], ['Accept' => 'text/html'], $resident);
        $this->assertSame(200, $response->statusCode());
        $body = $response->getBody();
        $this->assertStringContains('data-open-gate', $body);
        $this->assertStringContains('ОТКРЫТЬ', $body);
        $this->assertStringContains('/assets/js/remote.js', $body);
        $this->assertStringContains('h-[100dvh]', $body);

        // Anonymous visitors are redirected to the login page.
        $anon = TestApp::request('GET', '/remote', [], ['Accept' => 'text/html']);
        $this->assertSame(302, $anon->statusCode());
    }

    public function testCamerasEndpointListsCamerasBoundToGates(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '57']);
        $resident = TestApp::makeUser('remote-cam', 'Pass2026!x', 'resident', $residentId);

        $response = TestApp::request('GET', '/panel/cameras', [], ['Accept' => 'application/json'], $resident);
        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertTrue($data['ok']);
        $this->assertGreaterThan(0, count($data['data']), 'the config-synthesised main device has a camera by default');
        $camera = $data['data'][0];
        $this->assertSame('main', $camera['device']);
        $this->assertStringContains('/panel/snapshot', $camera['frame_url']);
    }

    /* ─────────────────── admin: device camera settings ─────────────────── */

    public function testDeviceFormStoresCameraSettingsAndFeedsCsp(): void
    {
        $admin = TestApp::makeAdmin('remote-admin');

        $post = [
            '_token' => TestApp::csrf(),
            'name' => 'Северный въезд', 'slug' => 'north', 'driver' => 'dryrun',
            'is_active' => '1',
            'camera_enabled' => '1',
            'camera_mode' => 'mjpeg',
            'camera_stream_url' => 'http://192.168.9.9:8080/stream',
            'camera_endpoint' => 'security/ipcam',
            'camera_cache_ttl' => '3',
            'camera_width' => '1280',
        ];
        $response = TestApp::request('POST', '/admin/gate/devices', $post, ['Accept' => 'application/json'], $admin);
        $this->assertContains($response->statusCode(), [200, 302], 'device must be saved');

        $row = TestApp::db()->table('devices')->where('slug', '=', 'north')->first();
        $this->assertTrue($row !== null);
        $options = json_decode((string) $row['options'], true);
        $this->assertSame('mjpeg', $options['camera']['mode']);
        $this->assertSame('http://192.168.9.9:8080/stream', $options['camera']['stream_url']);
        $this->assertSame(3, (int) $options['camera']['cache_ttl']);
        $this->assertTrue((bool) $options['camera']['enabled']);

        // The direct-stream origin is allowed by the CSP of the next page.
        TestApp::get(\BasIp\Device\DeviceManager::class)->flush();
        $html = TestApp::request('GET', '/remote', [], ['Accept' => 'text/html'], $admin);
        $this->assertSame(200, $html->statusCode());
        $csp = (string) ($html->headers()['Content-Security-Policy'] ?? '');
        $this->assertStringContains('192.168.9.9:8080', $csp);

        // …and the camera is listed for the remote with the direct URL.
        $cameras = TestApp::json(TestApp::request('GET', '/panel/cameras', [], ['Accept' => 'application/json'], $admin));
        $north = null;
        foreach ($cameras['data'] as $camera) {
            if ($camera['device'] === 'north') {
                $north = $camera;
            }
        }
        $this->assertTrue($north !== null, 'the north camera must be listed');
        $this->assertSame('mjpeg', $north['mode']);
        $this->assertSame('http://192.168.9.9:8080/stream', $north['stream_url']);
    }

    public function testStreamRefusesWhenTheCameraIsOff(): void
    {
        $admin = TestApp::makeAdmin('remote-admin2');
        TestApp::request('POST', '/admin/gate/devices', [
            '_token' => TestApp::csrf(),
            'name' => 'Без камеры', 'slug' => 'nostream', 'driver' => 'dryrun', 'is_active' => '1',
            'camera_enabled' => '1', 'camera_mode' => 'off',
        ], ['Accept' => 'application/json'], $admin);
        TestApp::get(\BasIp\Device\DeviceManager::class)->flush();

        $response = TestApp::request('GET', '/panel/camera/stream?device=nostream', [], ['Accept' => 'application/json'], $admin);
        $this->assertSame(404, $response->statusCode());
    }

    public function testCameraStreamUrlMustBeHttp(): void
    {
        $admin = TestApp::makeAdmin('remote-admin3');
        $response = TestApp::request('POST', '/admin/gate/devices', [
            '_token' => TestApp::csrf(),
            'name' => 'Плохой', 'slug' => 'badcam', 'driver' => 'dryrun', 'is_active' => '1',
            'camera_enabled' => '1', 'camera_mode' => 'mjpeg', 'camera_stream_url' => 'file:///etc/passwd',
        ], ['Accept' => 'application/json'], $admin);
        $this->assertSame(422, $response->statusCode());
        $this->assertTrue(TestApp::db()->table('devices')->where('slug', '=', 'badcam')->first() === null);
    }
}
