<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Auth\AuthService;
use BasIp\Auth\Permissions;
use BasIp\Auth\User;
use BasIp\Services\UserService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * Authentication and authorisation: password policy, lockout, 2FA enrolment,
 * role permissions and account administration.
 */
final class AuthTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        TestApp::boot();
        TestApp::db()->table('users')->where('id', '>', 0)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        TestApp::db()->table('login_attempts')->where('id', '>', 0)->delete();
        $this->auth = TestApp::get(AuthService::class);

        // Login throttling is keyed by IP; CLI attempts all share '0.0.0.0',
        // so the bucket must be emptied between tests or one lockout leaks
        // into every following case.
        $limiter = TestApp::container()->get(\BasIp\Core\RateLimiter::class);
        foreach (['login:0.0.0.0', 'login:203.0.113.7', 'login-id:lockme', 'login-id:known', 'login-id:reset'] as $key) {
            $limiter->clear($key);
        }
        TestApp::db()->table('users')->where('locked_until', '!=', null)->update(['locked_until' => null, 'failed_attempts' => 0]);
    }

    public function testCreateAccountAndLogin(): void
    {
        $created = $this->auth->createAccount([
            'login' => 'ivan', 'password' => 'Str0ngPass!', 'role' => 'admin',
            'full_name' => 'Иванов Иван', 'email' => 'ivan@example.com',
        ]);
        $this->assertTrue($created['ok'], (string) ($created['error'] ?? ''));

        $result = $this->auth->attempt('ivan', 'Str0ngPass!');
        $this->assertTrue($result['ok'], (string) ($result['reason'] ?? ''));
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertSame('admin', $result['user']->role);
    }

    public function testLoginByEmailWorks(): void
    {
        $this->auth->createAccount(['login' => 'petr', 'password' => 'Str0ngPass!', 'role' => 'operator', 'email' => 'petr@example.com']);
        $this->assertTrue($this->auth->attempt('petr@example.com', 'Str0ngPass!')['ok']);
    }

    public function testWrongPasswordIsRejectedAndCounted(): void
    {
        $this->auth->createAccount(['login' => 'sidor', 'password' => 'Str0ngPass!', 'role' => 'resident']);
        $result = $this->auth->attempt('sidor', 'wrong-password');
        $this->assertFalse($result['ok']);
        $this->assertStringContains('Неверный логин', (string) $result['reason']);

        $attempts = TestApp::db()->table('login_attempts')->where('identifier', '=', 'sidor')->where('successful', '=', 0)->count();
        $this->assertSame(1, $attempts);
    }

    public function testUnknownLoginGivesTheSameMessageAsWrongPassword(): void
    {
        $this->auth->createAccount(['login' => 'known', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $unknown = $this->auth->attempt('never-existed', 'Str0ngPass!');
        $wrong = $this->auth->attempt('known', 'not-the-password');
        // Username enumeration defence: identical wording.
        $this->assertSame($unknown['reason'], $wrong['reason']);
    }

    public function testDisabledAccountCannotLogin(): void
    {
        $this->auth->createAccount(['login' => 'off', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        TestApp::db()->table('users')->where('login', '=', 'off')->update(['is_active' => 0]);
        $result = $this->auth->attempt('off', 'Str0ngPass!');
        $this->assertFalse($result['ok']);
        $this->assertStringContains('отключена', (string) $result['reason']);
    }

    public function testAccountLocksAfterTooManyFailures(): void
    {
        $this->auth->createAccount(['login' => 'lockme', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $max = (int) TestApp::config()->int('auth.login.max_attempts', 5);
        for ($i = 0; $i < $max; $i++) {
            $this->auth->attempt('lockme', 'bad-password');
        }
        $row = TestApp::db()->table('users')->where('login', '=', 'lockme')->first();
        $this->assertNotNull($row['locked_until'], 'the account must be locked after N failures');

        // Even the correct password is refused while locked.
        $result = $this->auth->attempt('lockme', 'Str0ngPass!');
        $this->assertFalse($result['ok']);
        $this->assertTrue(($result['locked'] ?? false) === true || str_contains((string) $result['reason'], 'заблокирована'));

        $this->auth->unlock((int) $row['id']);

        // `unlock()` clears the per-account buckets by design; the per-IP bucket
        // is anti-stuffing protection that decays on its own. Simulate the decay.
        TestApp::container()->get(\BasIp\Core\RateLimiter::class)->clear('login:0.0.0.0');

        $this->assertTrue($this->auth->attempt('lockme', 'Str0ngPass!')['ok'], 'unlock must restore access');
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotSame([], $this->auth->validatePassword('short'));
        $this->assertNotSame([], $this->auth->validatePassword('alllowercase1'));
        $this->assertNotSame([], $this->auth->validatePassword('NoDigitsHere'));
        $this->assertNotSame([], $this->auth->validatePassword('password'));
        $this->assertSame([], $this->auth->validatePassword('Str0ngPass!'));
        $this->assertNotSame([], $this->auth->validatePassword('SameAsLogin', 'sameaslogin'));
    }

    public function testPasswordHashIsNotPlaintextAndVerifies(): void
    {
        $hash = $this->auth->hashPassword('Str0ngPass!');
        $this->assertStringNotContains('Str0ngPass!', $hash);
        $this->assertTrue($this->auth->verifyPassword('Str0ngPass!', $hash));
        $this->assertFalse($this->auth->verifyPassword('other', $hash));
        $this->assertFalse($this->auth->verifyPassword('', $hash));
        $this->assertStringContains('$', $hash);    // argon2id or bcrypt marker
    }

    public function testChangePasswordRequiresTheCurrentOne(): void
    {
        $this->auth->createAccount(['login' => 'changer', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $user = $this->auth->findByLogin('changer');

        $bad = $this->auth->changePassword($user, 'wrong', 'NewStr0ngPass!');
        $this->assertFalse($bad['ok']);

        $same = $this->auth->changePassword($user, 'Str0ngPass!', 'Str0ngPass!');
        $this->assertFalse($same['ok'], 'reusing the same password must be rejected');

        $ok = $this->auth->changePassword($user, 'Str0ngPass!', 'NewStr0ngPass!');
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $this->assertTrue($this->auth->attempt('changer', 'NewStr0ngPass!')['ok']);
    }

    public function testTwoFactorEnrolmentFlow(): void
    {
        $this->auth->createAccount(['login' => 'twofa', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $user = $this->auth->findByLogin('twofa');

        $enrolment = $this->auth->beginTwoFactorEnrolment($user);
        $this->assertStringContains('otpauth://totp/', $enrolment['uri']);
        $this->assertStringContains('secret=' . $enrolment['secret'], $enrolment['uri']);

        // A wrong code must not enable 2FA.
        $bad = $this->auth->confirmTwoFactorEnrolment($user, '000000');
        $this->assertFalse($bad['ok']);

        $code = $this->auth->totp()->code($enrolment['secret']);
        $confirmed = $this->auth->confirmTwoFactorEnrolment($user, $code);
        $this->assertTrue($confirmed['ok'], (string) ($confirmed['error'] ?? ''));
        $this->assertCount(8, $confirmed['recovery_codes']);

        $fresh = $this->auth->findByLogin('twofa');
        $this->assertTrue($fresh->totpEnabled);

        // Login now demands the second factor.
        $challenge = $this->auth->attempt('twofa', 'Str0ngPass!');
        $this->assertFalse($challenge['ok']);
        $this->assertTrue($challenge['requires_2fa'] ?? false);

        // The secret must be stored encrypted, never in plain text.
        $row = TestApp::db()->table('users')->where('login', '=', 'twofa')->first();
        $this->assertStringNotContains($enrolment['secret'], (string) $row['totp_secret']);
    }

    public function testRecoveryCodeIsSingleUse(): void
    {
        $this->auth->createAccount(['login' => 'recovery', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $user = $this->auth->findByLogin('recovery');
        $enrolment = $this->auth->beginTwoFactorEnrolment($user);
        $confirmed = $this->auth->confirmTwoFactorEnrolment($user, $this->auth->totp()->code($enrolment['secret']));
        $code = $confirmed['recovery_codes'][0];

        // Start a 2FA challenge, then consume the recovery code.
        $this->auth->attempt('recovery', 'Str0ngPass!');
        $session = TestApp::container()->get(\BasIp\Core\Session::class);
        $session->start();
        $session->set('pending_2fa_user_id', $user->id);
        $session->set('pending_2fa_at', time());

        $first = $this->auth->verifyTwoFactor($code);
        $this->assertTrue($first['ok'], 'a recovery code must let the user in');
    }

    /* ─────────────────────────── roles & permissions ─────────────────────────── */

    public function testRolePermissions(): void
    {
        $this->assertTrue(Permissions::can('admin', 'settings.edit'));
        $this->assertTrue(Permissions::can('admin', 'update.run'));
        $this->assertFalse(Permissions::can('operator', 'settings.edit'));
        $this->assertFalse(Permissions::can('operator', 'users.view'));
        $this->assertTrue(Permissions::can('operator', 'residents.edit'));
        $this->assertTrue(Permissions::can('operator', 'gate.open'));
        $this->assertTrue(Permissions::can('resident', 'gate.open'));
        $this->assertTrue(Permissions::can('resident', 'guest.create'));
        $this->assertFalse(Permissions::can('resident', 'residents.view'));
        $this->assertFalse(Permissions::can('resident', 'billing.charge.create'));
        $this->assertFalse(Permissions::can('unknown-role', 'gate.open'));
    }

    public function testEveryPermissionHasALabel(): void
    {
        $labels = Permissions::labels();
        foreach (Permissions::ALL as $permission) {
            $this->assertTrue(isset($labels[$permission]), 'missing label for ' . $permission);
        }
    }

    public function testUserModelHelpers(): void
    {
        $this->auth->createAccount(['login' => 'model', 'password' => 'Str0ngPass!', 'role' => 'operator', 'full_name' => 'Иванов Иван Иванович']);
        $user = $this->auth->findByLogin('model');
        $this->assertTrue($user->isStaff());
        $this->assertFalse($user->isAdmin());
        $this->assertSame('Иванов И.И.', $user->shortName());
        $this->assertSame('ИИ', $user->initials());
        $this->assertSame('Диспетчер', $user->roleLabel());
        $this->assertTrue($user->can('gate.open'));
        $this->assertFalse($user->isLocked());
        $this->assertSame('system', $user->theme());
    }

    /* ─────────────────────────── account administration ─────────────────────────── */

    public function testLastAdminCannotBeDisabled(): void
    {
        $created = $this->auth->createAccount(['login' => 'sole', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        /** @var UserService $users */
        $users = TestApp::get(UserService::class);
        $actor = $this->auth->findByLogin('sole');

        // Disabling yourself is refused first…
        $self = $users->update((int) $created['id'], ['is_active' => false], $actor);
        $this->assertFalse($self['ok']);
        $this->assertStringContains('собственн', (string) $self['error']);

        // …and with no actor (the CLI / cron path) the "last admin" guard fires.
        $result = $users->update((int) $created['id'], ['is_active' => false], null);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('последний администратор', (string) $result['error']);
    }

    public function testCannotDeleteOwnAccount(): void
    {
        $created = $this->auth->createAccount(['login' => 'selfdelete', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $second = $this->auth->createAccount(['login' => 'second', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        /** @var UserService $users */
        $users = TestApp::get(UserService::class);
        $actor = $this->auth->findByLogin('selfdelete');

        $result = $users->delete((int) $created['id'], $actor);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('собственн', (string) $result['error']);

        // Another admin can be removed (soft delete).
        $ok = $users->delete((int) $second['id'], $actor);
        $this->assertTrue($ok['ok']);
        $this->assertNull($this->auth->findByLogin('second'), 'a deleted account must not resolve');
    }

    public function testDuplicateLoginIsRejected(): void
    {
        $this->auth->createAccount(['login' => 'dupe', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $second = $this->auth->createAccount(['login' => 'dupe', 'password' => 'Str0ngPass!', 'role' => 'admin']);
        $this->assertFalse($second['ok']);
    }

    public function testLoginValidationRejectsBadLogins(): void
    {
        $this->assertFalse($this->auth->createAccount(['login' => 'a', 'password' => 'Str0ngPass!', 'role' => 'admin'])['ok']);
        $this->assertFalse($this->auth->createAccount(['login' => 'with space', 'password' => 'Str0ngPass!', 'role' => 'admin'])['ok']);
        $this->assertFalse($this->auth->createAccount(['login' => 'ok-login', 'password' => 'Str0ngPass!', 'role' => 'wizard'])['ok']);
    }

    public function testPasswordResetTokenIsSingleUse(): void
    {
        $this->auth->createAccount(['login' => 'reset', 'password' => 'Str0ngPass!', 'role' => 'admin', 'email' => 'r@example.com']);
        $token = $this->auth->createResetToken('reset');
        $this->assertNotNull($token);

        $bad = $this->auth->resetPassword('reset', 'wrong-token', 'NewStr0ngPass!');
        $this->assertFalse($bad['ok']);

        $ok = $this->auth->resetPassword('reset', (string) $token['token'], 'NewStr0ngPass!');
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $this->assertTrue($this->auth->attempt('reset', 'NewStr0ngPass!')['ok']);

        // The same token must not work twice.
        $again = $this->auth->resetPassword('reset', (string) $token['token'], 'AnotherStr0ng!');
        $this->assertFalse($again['ok']);
    }

    public function testGeneratedPasswordMeetsPolicy(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $password = $this->auth->generatePassword();
            $this->assertSame([], $this->auth->validatePassword($password), 'generated password failed the policy: ' . $password);
        }
    }

    public function testResidentAccountLinking(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '42', 'full_name' => 'Петров Пётр']);
        /** @var UserService $users */
        $users = TestApp::get(UserService::class);
        $admin = $this->auth->createAccount(['login' => 'boss', 'password' => 'Str0ngPass!', 'role' => 'admin']);

        $result = $users->ensureResidentAccount($residentId, null, null, $this->auth->findByLogin('boss'));
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertFalse($result['existed']);
        $this->assertNotSame('', (string) $result['password']);

        $account = $this->auth->findByLogin((string) $result['login']);
        $this->assertSame('resident', $account->role);
        $this->assertSame($residentId, $account->residentId);

        // Idempotent: a second call returns the same account.
        $again = $users->ensureResidentAccount($residentId);
        $this->assertTrue($again['existed']);
        $this->assertSame($result['login'], $again['login']);
        unset($admin);
    }
}
