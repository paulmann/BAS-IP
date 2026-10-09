<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Domain\Model\GuestPass;
use BasIp\Services\GuestPassService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * Guest passes: issuing, resolution by token/code/PIN, limits, expiry, revocation.
 */
final class GuestPassTest extends TestCase
{
    private GuestPassService $passes;
    private int $residentId;

    protected function setUp(): void
    {
        TestApp::boot();
        TestApp::db()->table('guest_passes')->where('id', '>', 0)->delete();
        TestApp::db()->table('gate_events')->where('id', '>', 0)->delete();
        TestApp::db()->table('residents')->where('id', '>', 0)->delete();
        $this->passes = TestApp::get(GuestPassService::class);
        $this->residentId = TestApp::makeResident(['apartment' => '7']);
    }

    public function testDefaultTtlIsTwelveHours(): void
    {
        $result = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->assertTrue($result['ok']);
        /** @var GuestPass $pass */
        $pass = $result['pass'];
        $this->assertSame(12, $pass->ttlHours);

        $from = strtotime((string) $pass->validFrom);
        $until = strtotime((string) $pass->validUntil);
        $this->assertSame(12 * 3600, $until - $from);
        $this->assertTrue($pass->isActive());
    }

    public function testTokenIsStoredHashedNotInPlain(): void
    {
        $result = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $token = (string) $result['token'];
        $this->assertSame(48, strlen($token));

        $row = TestApp::db()->table('guest_passes')->where('id', '=', (int) $result['id'])->first();
        $this->assertStringNotContains($token, (string) $row['token_hash'], 'the raw token must never be stored');
        $this->assertSame(64, strlen((string) $row['token_hash']) - strlen('hash:sha256:'));

        // …but it still resolves.
        $found = $this->passes->findByToken($token);
        $this->assertNotNull($found);
        $this->assertSame((int) $result['id'], $found->id);
    }

    public function testUnknownTokenDoesNotResolve(): void
    {
        $this->assertNull($this->passes->findByToken('deadbeef'));
        $this->assertNull($this->passes->findByToken(''));
    }

    public function testCodeAndPinLookup(): void
    {
        $result = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $code = (string) $result['code'];
        $pin = (string) ($result['pin'] ?? '');

        $this->assertMatches('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        $this->assertNotNull($this->passes->findByCode($code));
        $this->assertNotNull($this->passes->findByCode(strtolower(str_replace('-', '', $code))), 'lookup must tolerate case and a missing dash');
        if ($pin !== '') {
            $this->assertMatches('/^\d{4,8}$/', $pin);
            $this->assertNotNull($this->passes->findByCode($pin));
        }
        $this->assertNull($this->passes->findByCode('NOPE-NOPE'));
    }

    public function testIssuanceIsWrittenToTheGateLog(): void
    {
        $this->passes->create(['resident_id' => $this->residentId, 'notify' => false, 'guest_name' => 'Гость']);
        $event = TestApp::db()->table('gate_events')->where('action', '=', 'issued')->orderBy('id', 'desc')->first();
        $this->assertNotNull($event, 'issuing a pass must leave a trace in the gate log');
        $this->assertSame('guest', $event['access_type']);
        $this->assertStringContains('Гостевой доступ issued', (string) $event['reason']);
    }

    public function testActiveLimitPerResident(): void
    {
        $limit = (int) TestApp::get(\BasIp\Services\SettingsService::class)->int('guest.max_per_resident', 5);
        for ($i = 0; $i < $limit; $i++) {
            $this->assertTrue($this->passes->create(['resident_id' => $this->residentId, 'notify' => false])['ok']);
        }
        $extra = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->assertFalse($extra['ok']);
        $this->assertStringContains('лимит', (string) $extra['error']);
    }

    public function testDailyRateLimit(): void
    {
        $perDay = (int) TestApp::get(\BasIp\Core\Config::class)->int('guest.rate_limit_per_resident_day', 10);
        // Raise the active-pass ceiling so the daily limit is what trips.
        TestApp::get(\BasIp\Services\SettingsService::class)->set('guest.max_per_resident', 0);
        for ($i = 0; $i < $perDay; $i++) {
            $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        }
        $blocked = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContains('суточный лимит', (string) $blocked['error']);
        TestApp::get(\BasIp\Services\SettingsService::class)->set('guest.max_per_resident', 5);
    }

    public function testTtlIsCappedForResidentsButNotForStaff(): void
    {
        $max = (int) TestApp::get(\BasIp\Core\Config::class)->int('guest.max_ttl_hours', 72);
        $result = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 10000, 'notify' => false]);
        $this->assertTrue($result['ok']);
        $this->assertSame($max, $result['pass']->ttlHours, 'a resident cannot exceed the configured maximum');
    }

    public function testPlateIsRequiredWhenConfigured(): void
    {
        TestApp::get(\BasIp\Services\SettingsService::class)->set('guest.require_plate', true);
        $without = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->assertFalse($without['ok']);
        $this->assertStringContains('номер автомобиля', (string) $without['error']);

        $with = $this->passes->create(['resident_id' => $this->residentId, 'car_plate' => 'а 123 вс 77', 'notify' => false]);
        $this->assertTrue($with['ok']);
        $this->assertSame('A123BC77', $with['pass']->carPlateNormalized);
        TestApp::get(\BasIp\Services\SettingsService::class)->set('guest.require_plate', false);
    }

    public function testDisabledResidentCannotIssuePasses(): void
    {
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['is_active' => 0]);
        $result = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->assertFalse($result['ok']);
    }

    public function testRevokeAndExtend(): void
    {
        $created = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 2, 'notify' => false]);
        $id = (int) $created['id'];

        $this->assertTrue($this->passes->extend($id, 5));
        $extended = $this->passes->find($id);
        $this->assertGreaterThan(strtotime((string) $created['pass']->validUntil), strtotime((string) $extended->validUntil));

        $this->assertTrue($this->passes->revoke($id, 'отменён визит'));
        $revoked = $this->passes->find($id);
        $this->assertTrue($revoked->isRevoked());
        $this->assertFalse($revoked->isActive());
        $this->assertSame('отменён визит', $revoked->revokeReason);
        $this->assertFalse($this->passes->revoke($id, 'again'), 'revoking twice is a no-op');
    }

    public function testExpireStaleMarksOverduePasses(): void
    {
        $created = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 1, 'notify' => false]);
        TestApp::db()->table('guest_passes')->where('id', '=', (int) $created['id'])->update([
            'valid_until' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->assertSame(1, $this->passes->expireStale());
        $this->assertSame(GuestPass::STATUS_EXPIRED, $this->passes->find((int) $created['id'])->status);
    }

    public function testCountActiveAndList(): void
    {
        $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $this->passes->create(['resident_id' => $this->residentId, 'notify' => false, 'guest_name' => 'Такси']);

        $this->assertSame(2, $this->passes->countActiveFor($this->residentId));
        $this->assertCount(2, $this->passes->activeForResident($this->residentId));

        $all = $this->passes->list(['status' => 'all'], 10, 1);
        $this->assertSame(2, $all->total());

        $filtered = $this->passes->list(['status' => 'all', 'q' => 'Такси'], 10, 1);
        $this->assertSame(1, $filtered->total());
    }

    public function testLinkUsesConfiguredBaseOrRelativePath(): void
    {
        $created = $this->passes->create(['resident_id' => $this->residentId, 'notify' => false]);
        $link = (string) $created['link'];
        $this->assertStringContains('/guest/', $link);
        $this->assertStringContains((string) $created['token'], $link);

        $absolute = $this->passes->linkFor('abc123', 'https://gate.example.com/');
        $this->assertSame('https://gate.example.com/guest/abc123', $absolute);
    }

    public function testTimeLeftAndProgress(): void
    {
        $created = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'notify' => false]);
        $pass = $this->passes->find((int) $created['id']);
        $this->assertStringContains('осталось', $pass->timeLeft());
        $this->assertLessThan(5.0, $pass->percentElapsed());

        TestApp::db()->table('guest_passes')->where('id', '=', $pass->id)->update([
            'valid_until' => date('Y-m-d H:i:s', time() - 10),
        ]);
        $expired = $this->passes->find($pass->id);
        $this->assertSame('истёк', $expired->timeLeft());
        $this->assertTrue($expired->isExpired());
    }

    public function testPurgeOldOnlyRemovesClosedPasses(): void
    {
        $active = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'notify' => false]);
        $old = $this->passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 1, 'notify' => false]);
        TestApp::db()->table('guest_passes')->where('id', '=', (int) $old['id'])->update([
            'status' => GuestPass::STATUS_EXPIRED,
            'valid_until' => date('Y-m-d H:i:s', strtotime('-200 days')),
        ]);

        $this->assertSame(1, $this->passes->purgeOld(90));
        $this->assertNotNull($this->passes->find((int) $active['id']));
        $this->assertNull($this->passes->find((int) $old['id']));
    }
}
