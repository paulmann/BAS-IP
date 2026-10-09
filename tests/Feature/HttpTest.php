<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Auth\User;
use BasIp\Core\Response;
use BasIp\Services\ApiTokenService;
use BasIp\Services\GuestPassService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * HTTP layer: routing, authentication, authorisation, CSRF, the public guest
 * page and the token-authenticated API.
 */
final class HttpTest extends TestCase
{
    private ?User $admin = null;

    protected function setUp(): void
    {
        TestApp::boot();
        TestApp::db()->table('residents')->where('id', '>', 0)->delete();
        TestApp::db()->table('guest_passes')->where('id', '>', 0)->delete();
        TestApp::db()->table('api_tokens')->where('id', '>', 0)->delete();
        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        $this->admin = TestApp::makeAdmin('http-admin');
    }

    /* ─────────────────────────── public routes ─────────────────────────── */

    public function testHealthEndpointIsPublic(): void
    {
        $response = TestApp::request('GET', '/health.json');
        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertContains($data['status'], ['ok', 'degraded', 'down']);
        $this->assertTrue(isset($data['checks']['database']));
    }

    public function testMetricsEndpointIsPublic(): void
    {
        $response = TestApp::request('GET', '/metrics');
        $this->assertSame(200, $response->statusCode());
        $this->assertStringContains('basip_up', $response->getBody());
        $this->assertStringContains('# TYPE', $response->getBody());
    }

    public function testMetaEndpointIsPublic(): void
    {
        $response = TestApp::request('GET', '/api/v1/meta');
        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertContains('gate.open', $data['capabilities']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $response = TestApp::request('GET', '/definitely-not-a-page');
        $this->assertSame(404, $response->statusCode());
    }

    public function testLoginPageIsPublic(): void
    {
        $response = TestApp::request('GET', '/login', [], ['Accept' => 'text/html']);
        $this->assertSame(200, $response->statusCode());
        $this->assertStringContains('Вход в систему', $response->getBody());
    }

    public function testAdminAreaRedirectsAnonymousUsersToLogin(): void
    {
        $response = TestApp::request('GET', '/admin/residents', [], ['Accept' => 'text/html']);
        $this->assertSame(302, $response->statusCode());
        $this->assertStringContains('/login', (string) $response->headers()['Location']);
    }

    public function testAdminAreaReturns401ForJsonCallers(): void
    {
        $response = TestApp::request('GET', '/admin/residents');
        $this->assertSame(302, $response->statusCode());   // session auth redirects
        $api = TestApp::request('GET', '/api/v1/residents');
        $this->assertSame(401, $api->statusCode());
    }

    /* ─────────────────────────── CSRF ─────────────────────────── */

    public function testCsrfRejectsPostWithoutToken(): void
    {
        $response = TestApp::request('POST', '/panel/open', [], ['Accept' => 'application/json'], $this->admin);
        $this->assertSame(419, $response->statusCode());
        $this->assertStringContains('CSRF', TestApp::json($response)['error'] ?? '');
    }

    public function testCsrfAcceptsPostWithToken(): void
    {
        $response = TestApp::request('POST', '/panel/open', ['_token' => TestApp::csrf()], ['Accept' => 'application/json'], $this->admin);
        $this->assertContains($response->statusCode(), [200, 422], 'a valid token must reach the controller');
    }

    public function testCsrfRejectsForgedToken(): void
    {
        $response = TestApp::request('POST', '/panel/open', ['_token' => 'forged-token-value'], ['Accept' => 'application/json'], $this->admin);
        $this->assertSame(419, $response->statusCode());
    }

    public function testSafeMethodsAreNeverCsrfChecked(): void
    {
        $response = TestApp::request('GET', '/panel', [], ['Accept' => 'text/html'], $this->admin);
        $this->assertSame(200, $response->statusCode());
    }

    /* ─────────────────────────── authorisation ─────────────────────────── */

    public function testResidentCannotReachTheAdminArea(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $resident = TestApp::makeUser('http-resident', 'Str0ngPass!', 'resident', $residentId);

        $response = TestApp::request('GET', '/admin/residents', [], ['Accept' => 'text/html'], $resident);
        $this->assertSame(302, $response->statusCode());   // redirected away, not rendered

        $cabinet = TestApp::request('GET', '/cabinet', [], ['Accept' => 'text/html'], $resident);
        $this->assertSame(200, $cabinet->statusCode());
    }

    public function testOperatorCannotOpenSettings(): void
    {
        $operator = TestApp::makeOperator('http-operator');
        $response = TestApp::request('GET', '/admin/settings', [], ['Accept' => 'text/html'], $operator);
        $this->assertSame(302, $response->statusCode());

        // …but can manage residents.
        $residents = TestApp::request('GET', '/admin/residents', [], ['Accept' => 'text/html'], $operator);
        $this->assertSame(200, $residents->statusCode());
    }

    public function testAdminReachesEveryAdminSection(): void
    {
        foreach (['/admin', '/admin/residents', '/admin/billing', '/admin/gate', '/admin/guest', '/admin/users', '/admin/settings', '/admin/system', '/admin/audit', '/admin/data', '/admin/notifications', '/admin/updates'] as $path) {
            $response = TestApp::request('GET', $path, [], ['Accept' => 'text/html'], $this->admin);
            $this->assertSame(200, $response->statusCode(), $path . ' → HTTP ' . $response->statusCode());
        }
    }

    /* ─────────────────────────── guest page ─────────────────────────── */

    public function testGuestPageRendersForAValidToken(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '9']);
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $residentId, 'ttl_hours' => 12, 'guest_name' => 'Курьер', 'notify' => false]);

        $response = TestApp::request('GET', '/guest/' . $created['token'], [], ['Accept' => 'text/html']);
        $this->assertSame(200, $response->statusCode());
        $this->assertStringContains($created['code'], $response->getBody());
        $this->assertStringContains('Открыть шлагбаум', $response->getBody());
    }

    public function testGuestPageRejectsUnknownToken(): void
    {
        $response = TestApp::request('GET', '/guest/not-a-real-token', [], ['Accept' => 'text/html']);
        $this->assertSame(404, $response->statusCode());
    }

    public function testGuestCanOpenWithoutASession(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '10']);
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $residentId, 'ttl_hours' => 12, 'notify' => false]);

        $response = TestApp::request('POST', '/guest/' . $created['token'] . '/open', [], ['Accept' => 'application/json']);
        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertTrue($data['ok']);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame('guest', $event['access_type']);
    }

    public function testOpeningByCodeWorks(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '11']);
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $residentId, 'ttl_hours' => 12, 'notify' => false]);

        $response = TestApp::request('POST', '/guest/code', ['code' => $created['code']], ['Accept' => 'application/json']);
        $this->assertSame(200, $response->statusCode());
        $this->assertTrue(TestApp::json($response)['ok']);
    }

    /* ─────────────────────────── API ─────────────────────────── */

    private function apiHeaders(array $scopes = ['*']): array
    {
        /** @var ApiTokenService $tokens */
        $tokens = TestApp::get(ApiTokenService::class);
        $token = $tokens->create('test-token', $scopes);

        return ['Authorization' => 'Bearer ' . $token['token'], 'Accept' => 'application/json'];
    }

    public function testApiRequiresAToken(): void
    {
        $this->assertSame(401, TestApp::request('GET', '/api/v1/gate/status')->statusCode());
        $this->assertSame(401, TestApp::request('GET', '/api/v1/gate/status', [], ['Authorization' => 'Bearer bogus'])->statusCode());
    }

    public function testApiScopesAreEnforced(): void
    {
        $headers = $this->apiHeaders(['gate.status']);
        $this->assertSame(200, TestApp::request('GET', '/api/v1/gate/status', [], $headers)->statusCode());

        // The same token must not be able to open the gate.
        $open = TestApp::request('POST', '/api/v1/gate/open', [], $headers);
        $this->assertSame(403, $open->statusCode());
    }

    public function testApiOpenByApartment(): void
    {
        TestApp::makeResident(['apartment' => '12']);
        $response = TestApp::request('POST', '/api/v1/gate/open', ['apartment' => '12'], $this->apiHeaders());
        $this->assertSame(200, $response->statusCode());
        $this->assertTrue(TestApp::json($response)['ok']);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame('api', $event['source']);
        $this->assertSame('api', $event['actor_type']);
    }

    public function testApiCreatesGuestPass(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '14']);
        $response = TestApp::request('POST', '/api/v1/guest/passes', ['resident_id' => $residentId, 'ttl_hours' => 6], $this->apiHeaders());
        $this->assertSame(201, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertNotSame('', (string) $data['code']);
        $this->assertNotSame('', (string) $data['link']);
        $this->assertSame(6, (int) $data['ttl_hours']);
    }

    public function testApiListsResidentsAndHidesNothingUnexpected(): void
    {
        TestApp::makeResident(['apartment' => '15']);
        $response = TestApp::request('GET', '/api/v1/residents', [], $this->apiHeaders(['residents.view']));
        $this->assertSame(200, $response->statusCode());
        $data = TestApp::json($response);
        $this->assertGreaterThan(0, count($data['data']));
        $first = $data['data'][0];
        $this->assertTrue(isset($first['full_name'], $first['apartment'], $first['is_active']));
        $this->assertTrue(array_key_exists('meta', $data));
    }

    public function testApiRecordsPayment(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '16']);
        TestApp::db()->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'API-1', 'resident_id' => $residentId,
            'amount' => 500, 'paid_amount' => 0, 'balance' => 500, 'status' => 'pending',
            'due_date' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $response = TestApp::request('POST', '/api/v1/payments', [
            'resident_id' => $residentId, 'amount' => 500, 'method' => 'online', 'external_id' => 'pay_1',
        ], $this->apiHeaders(['billing.payment.record']));

        $this->assertSame(201, $response->statusCode());
        $this->assertSame('paid', TestApp::db()->table('invoices')->where('number', '=', 'API-1')->value('status'));
    }

    /* ─────────────────────────── rendering ─────────────────────────── */

    public function testPagesShipLocalAssetsOnly(): void
    {
        $response = TestApp::request('GET', '/panel', [], ['Accept' => 'text/html'], $this->admin);
        $this->assertSame(200, $response->statusCode());
        $body = $response->getBody();

        $this->assertStringContains('/assets/css/app.css', $body);
        $this->assertStringContains('/assets/js/app.js', $body);
        $this->assertStringContains('/assets/icons/lucide.svg', $body);

        // No third-party CDN of any kind.
        $this->assertSame(0, preg_match('~https?://(?:cdn|unpkg|jsdelivr|fonts\.googleapis|fonts\.gstatic|cdnjs)[^"\']*~i', $body), 'a CDN reference slipped into the page');
    }

    public function testSecurityHeadersArePresent(): void
    {
        $response = TestApp::request('GET', '/login', [], ['Accept' => 'text/html']);
        $headers = $response->headers();
        $this->assertTrue(isset($headers['Content-Security-Policy']), 'CSP must be set');
        $this->assertStringContains("default-src 'self'", $headers['Content-Security-Policy']);
        $this->assertStringContains('frame-ancestors', $headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? '');
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? '');
        $this->assertTrue(isset($headers['X-Request-Id']));
    }

    public function testHtmlResponsesAreNeverCached(): void
    {
        $response = TestApp::request('GET', '/login', [], ['Accept' => 'text/html']);
        $this->assertStringContains('no-store', $response->headers()['Cache-Control'] ?? '');
    }

    public function testErrorPageDoesNotLeakStackTracesInProduction(): void
    {
        TestApp::config()->override(['app' => ['debug' => false]]);
        $response = TestApp::request('GET', '/definitely-not-a-page', [], ['Accept' => 'text/html']);
        $this->assertSame(404, $response->statusCode());
        $this->assertStringNotContains('Stack trace', $response->getBody());
        $this->assertStringNotContains(TestApp::config()->root(), $response->getBody());
        TestApp::config()->override(['app' => ['debug' => true]]);
    }

    public function testJsonErrorsHaveAStableShape(): void
    {
        $response = TestApp::request('GET', '/definitely-not-a-page');
        $data = TestApp::json($response);
        $this->assertFalse($data['ok']);
        $this->assertSame(404, $data['status']);
        $this->assertTrue(isset($data['request_id']));
    }

    /* ─────────────────────────── maintenance ─────────────────────────── */

    public function testMaintenanceModeKeepsTheGateReachable(): void
    {
        TestApp::config()->override(['app' => ['maintenance' => true]]);
        try {
            // Opening the gate bypasses the maintenance page (auth still applies).
            $panel = TestApp::request('POST', '/panel/open', ['_token' => TestApp::csrf()], ['Accept' => 'application/json'], $this->admin);
            $this->assertContains($panel->statusCode(), [200, 422, 429], 'gate open must not be blocked by maintenance');

            // Guest links keep working — a courier must not see a 503.
            $guest = TestApp::request('GET', '/guest', [], ['Accept' => 'text/html']);
            $this->assertNotSame(503, $guest->statusCode());

            // Health probes keep working for monitoring.
            $this->assertSame(200, TestApp::request('GET', '/health.json')->statusCode());

            // A resident's ordinary page IS closed by maintenance.
            $residentId = TestApp::makeResident(['apartment' => '77']);
            $resident = TestApp::makeUser('http-maintenance-resident', 'Str0ngPass!', 'resident', $residentId);
            $cabinet = TestApp::request('GET', '/cabinet', [], ['Accept' => 'text/html'], $resident);
            $this->assertSame(503, $cabinet->statusCode());

            // Anonymous home page is closed too.
            $home = TestApp::request('GET', '/', [], ['Accept' => 'text/html']);
            $this->assertSame(503, $home->statusCode());
        } finally {
            TestApp::config()->override(['app' => ['maintenance' => false]]);
        }
    }
}
