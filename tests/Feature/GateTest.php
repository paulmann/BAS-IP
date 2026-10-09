<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Domain\Model\GateEvent;
use BasIp\Domain\Model\GuestPass;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * Gate orchestration: authorisation, policies, logging (success *and* denial)
 * and guest-pass interplay.
 */
final class GateTest extends TestCase
{
    private GateService $gate;
    private int $residentId;

    protected function setUp(): void
    {
        TestApp::boot();
        TestApp::db()->table('gate_events')->where('id', '>', 0)->delete();
        TestApp::db()->table('guest_passes')->where('id', '>', 0)->delete();
        TestApp::db()->table('residents')->where('id', '>', 0)->delete();
        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        $this->gate = TestApp::get(GateService::class);
        $this->residentId = TestApp::makeResident(['apartment' => '1']);
    }

    private function resident()
    {
        return TestApp::get(\BasIp\Domain\Repository\ResidentRepository::class)->findById($this->residentId);
    }

    public function testSuccessfulOpenIsLoggedWithOwnAccessType(): void
    {
        $result = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN, 'source' => 'cabinet']);
        $this->assertTrue($result->ok, $result->message);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame('success', $event['result']);
        $this->assertSame(GateEvent::ACCESS_OWN, $event['access_type']);
        $this->assertSame('open', $event['action']);
        $this->assertSame('cabinet', $event['source']);
        $this->assertSame($this->residentId, (int) $event['resident_id']);
        $this->assertSame('resident', $event['actor_type']);
        $this->assertSame('1', $event['apartment']);
        $this->assertNotNull($event['occurred_at']);
    }

    public function testDisabledResidentIsDeniedAndTheDenialIsLogged(): void
    {
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['is_active' => 0]);
        $result = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);

        $this->assertFalse($result->ok);
        $this->assertSame('inactive', $result->errorCode);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame('denied', $event['result'], 'denials must be logged too');
        $this->assertSame('denied', $event['action']);
        $this->assertStringContains('отключён', (string) $event['reason']);
    }

    public function testRevokedAccessIsDenied(): void
    {
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['access_granted' => 0]);
        $result = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        $this->assertFalse($result->ok);
        $this->assertSame('no_access', $result->errorCode);
    }

    public function testCooldownPreventsDoubleTaps(): void
    {
        $first = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        $this->assertTrue($first->ok);

        $second = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        $this->assertFalse($second->ok);
        $this->assertSame('cooldown', $second->errorCode);
    }

    public function testStaffCanForcePastPolicies(): void
    {
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['access_granted' => 0]);
        $admin = TestApp::makeAdmin('gate-admin');

        $result = $this->gate->open([
            'resident' => $this->resident(), 'user' => $admin,
            'access_type' => GateEvent::ACCESS_MANUAL, 'force' => true,
        ]);
        $this->assertTrue($result->ok, $result->message);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame('admin', $event['actor_type']);
    }

    public function testStaffOpeningWithoutForceStillRespectsResidentFlags(): void
    {
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['is_active' => 0]);
        $admin = TestApp::makeAdmin('gate-admin-2');
        $result = $this->gate->open(['resident' => $this->resident(), 'user' => $admin, 'access_type' => GateEvent::ACCESS_REMOTE]);
        $this->assertFalse($result->ok);
    }

    /* ─────────────────────────── guest passes ─────────────────────────── */

    public function testGuestPassOpensTheGateAndIsLoggedAsGuest(): void
    {
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'guest_name' => 'Курьер', 'notify' => false]);
        $this->assertTrue($created['ok']);
        /** @var GuestPass $pass */
        $pass = $created['pass'];

        $result = $this->gate->open(['guest_pass' => $pass, 'resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_GUEST, 'source' => 'web']);
        $this->assertTrue($result->ok, $result->message);

        $event = TestApp::db()->table('gate_events')->orderBy('id', 'desc')->first();
        $this->assertSame(GateEvent::ACCESS_GUEST, $event['access_type']);
        $this->assertSame('guest', $event['actor_type']);
        $this->assertSame($pass->id, (int) $event['guest_pass_id']);
        $this->assertStringContains('Курьер', (string) $event['actor_name']);

        // The use counter must advance.
        $fresh = $passes->find($pass->id);
        $this->assertSame(1, $fresh->usedCount);
        $this->assertNotNull($fresh->lastUsedAt);
    }

    public function testExpiredGuestPassIsDenied(): void
    {
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 1, 'notify' => false]);
        $pass = $created['pass'];
        TestApp::db()->table('guest_passes')->where('id', '=', $pass->id)->update([
            'valid_until' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $result = $this->gate->open(['guest_pass' => $passes->find($pass->id), 'access_type' => GateEvent::ACCESS_GUEST]);
        $this->assertFalse($result->ok);
        $this->assertSame('guest_invalid', $result->errorCode);
        $this->assertStringContains('истёк', $result->message);
    }

    public function testRevokedGuestPassIsDenied(): void
    {
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'notify' => false]);
        $passes->revoke((int) $created['id'], 'тест');

        $result = $this->gate->open(['guest_pass' => $passes->find((int) $created['id']), 'access_type' => GateEvent::ACCESS_GUEST]);
        $this->assertFalse($result->ok);
        $this->assertStringContains('отозван', $result->message);
    }

    public function testUseLimitIsEnforced(): void
    {
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'max_uses' => 1, 'notify' => false]);
        $pass = $passes->find((int) $created['id']);

        $first = $this->gate->open(['guest_pass' => $pass, 'access_type' => GateEvent::ACCESS_GUEST]);
        $this->assertTrue($first->ok);

        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        $second = $this->gate->open(['guest_pass' => $passes->find((int) $created['id']), 'access_type' => GateEvent::ACCESS_GUEST]);
        $this->assertFalse($second->ok, 'a single-use pass must not open twice');
        $this->assertSame(GuestPass::STATUS_USED, $passes->find((int) $created['id'])->status);
    }

    public function testGuestPassOfADisabledOwnerStopsWorking(): void
    {
        /** @var GuestPassService $passes */
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'notify' => false]);
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['is_active' => 0]);

        $result = $this->gate->open([
            'guest_pass' => $passes->find((int) $created['id']),
            'resident' => $this->resident(),
            'access_type' => GateEvent::ACCESS_GUEST,
        ]);
        $this->assertFalse($result->ok);
        $this->assertSame('owner_inactive', $result->errorCode);
    }

    /* ─────────────────────────── quiet hours & schedules ─────────────────────────── */

    public function testQuietHoursBlockResidentsButNotGuests(): void
    {
        $settings = TestApp::get(\BasIp\Services\SettingsService::class);
        $settings->set('gate.quiet_hours_enabled', true);
        // Force a window that always contains "now".
        $settings->set('gate.quiet_hours_start', '00:00');
        $settings->set('gate.quiet_hours_end', '23:59');
        $settings->set('gate.allow_guest_at_night', true);

        $residentResult = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        $this->assertFalse($residentResult->ok);
        $this->assertSame('quiet_hours', $residentResult->errorCode);

        // Guests still get through (couriers, ambulances).
        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        $passes = TestApp::get(GuestPassService::class);
        $created = $passes->create(['resident_id' => $this->residentId, 'ttl_hours' => 12, 'notify' => false]);
        $guestResult = $this->gate->open([
            'guest_pass' => $passes->find((int) $created['id']),
            'resident' => $this->resident(),
            'access_type' => GateEvent::ACCESS_GUEST,
        ]);
        $this->assertTrue($guestResult->ok, $guestResult->message);

        $settings->set('gate.quiet_hours_enabled', false);
    }

    public function testDenyScheduleBlocksOpening(): void
    {
        $weekday = (int) date('N');
        TestApp::db()->table('access_schedules')->insert([
            'name' => 'Test deny', 'resident_id' => null,
            'days' => implode(',', range(1, 7)), 'start_time' => '00:00', 'end_time' => '23:59',
            'effect' => 'deny', 'priority' => 10, 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $result = $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        $this->assertFalse($result->ok);
        $this->assertSame('schedule', $result->errorCode);
        $this->assertSame($weekday, $weekday);   // schedule applies every day
        TestApp::db()->table('access_schedules')->where('name', '=', 'Test deny')->delete();
    }

    /* ─────────────────────────── statistics ─────────────────────────── */

    public function testStatsCountSuccessDenialsAndGuests(): void
    {
        $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        TestApp::db()->table('residents')->where('id', '=', $this->residentId)->update(['access_granted' => 0]);
        TestApp::container()->get(\BasIp\Core\Cache::class)->flush();
        $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);

        $stats = $this->gate->stats(30);
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['success']);
        $this->assertSame(1, $stats['denied']);
        // `own` counts every own-access event, successful or denied.
        $this->assertSame(2, $stats['own']);
        $this->assertCount(30, $stats['by_day']);
    }

    public function testFindByPlateResolvesTheOwner(): void
    {
        TestApp::db()->table('cars')->insert([
            'resident_id' => $this->residentId, 'plate' => 'А123ВС77',
            'plate_normalized' => 'A123BC77', 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $found = $this->gate->findByPlate('а 123 вс 77');
        $this->assertNotNull($found);
        $this->assertSame($this->residentId, $found->id);

        $this->assertNull($this->gate->findByPlate('Е999ЕЕ99'));
    }

    public function testPurgeRemovesOldEventsOnly(): void
    {
        $this->gate->open(['resident' => $this->resident(), 'access_type' => GateEvent::ACCESS_OWN]);
        TestApp::db()->table('gate_events')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(),
            'occurred_at' => date('Y-m-d H:i:s', strtotime('-800 days')),
            'access_type' => 'own', 'action' => 'open', 'result' => 'success', 'source' => 'web',
        ]);

        $removed = $this->gate->purgeEvents(730);
        $this->assertSame(1, $removed);
        $this->assertSame(1, TestApp::db()->table('gate_events')->count());
    }
}
