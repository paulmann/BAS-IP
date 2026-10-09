<?php

declare(strict_types=1);

namespace BasIp\Auth;

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Logger;
use BasIp\Core\RateLimiter;
use BasIp\Core\Request;
use BasIp\Core\Session;
use BasIp\Db\Database;
use BasIp\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Authentication use-cases: login (with lockout + 2FA), password policy,
 * password changes/resets, remember-me, session bookkeeping and account
 * administration.
 */
final class AuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly Session $session,
        private readonly Guard $guard,
        private readonly Crypto $crypto,
        private readonly RateLimiter $limiter,
    ) {
    }

    /* ─────────────────────────── login ─────────────────────────── */

    /**
     * @return array{ok: bool, user?: User, reason?: string, requires_2fa?: bool, retry_after?: int, locked?: bool}
     */
    public function attempt(string $identifier, string $password, ?Request $request = null, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $ip = $request?->ip($this->config) ?? '0.0.0.0';
        $ua = $request?->userAgent() ?? 'cli';

        if ($this->isThrottled($ip, $identifier)) {
            $retry = $this->throttleRetryAfter($ip, $identifier);

            return ['ok' => false, 'reason' => 'Слишком много попыток входа. Повторите позже.', 'retry_after' => $retry, 'locked' => true];
        }

        $user = $this->guard->findByLogin($identifier);

        // Constant-ish time even for unknown logins (username enumeration defence).
        if ($user === null) {
            $this->dummyVerify($password);
            $this->recordAttempt($identifier, $ip, $ua, false, null, 'unknown_account');

            return $this->failure($identifier, $ip, 'Неверный логин или пароль.');
        }

        if (!$user->isActive) {
            $this->recordAttempt($identifier, $ip, $ua, false, $user->id, 'inactive');

            return $this->failure($identifier, $ip, 'Учётная запись отключена.');
        }

        if ($user->isLocked($this->clock->now())) {
            $seconds = max(1, (int) strtotime((string) $user->lockedUntil) - $this->clock->now());

            return ['ok' => false, 'reason' => 'Учётная запись временно заблокирована.', 'retry_after' => $seconds, 'locked' => true];
        }

        if (!$this->verifyPassword($password, $user->passwordHash)) {
            $this->registerFailure($user);
            $this->recordAttempt($identifier, $ip, $ua, false, $user->id, 'bad_password');

            return $this->failure($identifier, $ip, 'Неверный логин или пароль.');
        }

        if ($this->needsRehash($user->passwordHash)) {
            $this->setPassword($user->id, $password, false);
        }

        // Two-factor step.
        if ($user->totpEnabled) {
            $this->session->set('pending_2fa_user_id', $user->id);
            $this->session->set('pending_2fa_at', $this->clock->now());
            $this->session->set('pending_2fa_remember', $remember ? 1 : 0);
            $this->recordAttempt($identifier, $ip, $ua, false, $user->id, '2fa_required');

            return ['ok' => false, 'requires_2fa' => true, 'reason' => 'Введите код из приложения-аутентификатора.'];
        }

        $this->completeLogin($user, $remember, $request);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Second step of the login flow.
     *
     * @return array{ok: bool, user?: User, reason?: string}
     */
    public function verifyTwoFactor(string $code, ?Request $request = null): array
    {
        $userId = $this->session->get('pending_2fa_user_id');
        $since  = (int) $this->session->get('pending_2fa_at', 0);
        if (!is_int($userId) || $userId <= 0 || $this->clock->now() - $since > 600) {
            $this->session->forget('pending_2fa_user_id');

            return ['ok' => false, 'reason' => 'Сессия подтверждения истекла. Войдите заново.'];
        }

        $user = $this->guard->findById($userId);
        if ($user === null || !$user->isActive) {
            return ['ok' => false, 'reason' => 'Учётная запись недоступна.'];
        }

        $ip = $request?->ip($this->config) ?? '0.0.0.0';
        $ua = $request?->userAgent() ?? 'cli';
        $limiter = $this->limiter->attempt('2fa:' . $userId, 8, 600);
        if (!$limiter['allowed']) {
            return ['ok' => false, 'reason' => 'Слишком много попыток ввода кода. Повторите через ' . $limiter['retryAfter'] . ' с.'];
        }

        $secret = $this->guard->decryptSecret($user->totpSecret);
        $totp   = $this->totp();

        if ($secret !== null && $totp->verify($secret, $code)) {
            $this->limiter->clear('2fa:' . $userId);
            $remember = (bool) $this->session->get('pending_2fa_remember', false);
            $this->session->forget('pending_2fa_user_id');
            $this->session->forget('pending_2fa_at');
            $this->recordAttempt($user->login, $ip, $ua, true, $user->id, null, 'totp');
            $this->completeLogin($user, $remember, $request);

            return ['ok' => true, 'user' => $user];
        }

        // Recovery code?
        if ($this->consumeRecoveryCode($user, $code)) {
            $this->limiter->clear('2fa:' . $userId);
            $this->session->forget('pending_2fa_user_id');
            $this->recordAttempt($user->login, $ip, $ua, true, $user->id, null, 'recovery');
            $this->completeLogin($user, (bool) $this->session->get('pending_2fa_remember', false), $request);

            return ['ok' => true, 'user' => $user];
        }

        $this->recordAttempt($user->login, $ip, $ua, false, $user->id, 'bad_2fa', 'totp');

        return ['ok' => false, 'reason' => 'Неверный код.'];
    }

    private function completeLogin(User $user, bool $remember, ?Request $request): void
    {
        $ip = $request?->ip($this->config) ?? null;
        $this->db->table('users')->where('id', '=', $user->id)->update([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => $this->clock->format(),
            'last_login_ip'   => $ip,
            'updated_at'      => $this->clock->format(),
        ]);

        $this->guard->login($user, $remember, $request);
        $this->limiter->clear('login:' . $ip);

        $this->logger->channel('audit')->info('Вход выполнен', [
            'action' => 'login', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'subject_label' => $user->login, 'actor_name' => $user->login, 'actor_role' => $user->role,
            'ip' => $ip, 'user_agent' => $request?->userAgent(), 'is_security' => true,
        ]);
    }

    /** @return array{ok: bool, reason: string} */
    private function failure(string $identifier, string $ip, string $reason): array
    {
        $this->limiter->attempt('login:' . $ip, $this->config->int('auth.login.max_attempts', 5), $this->config->int('auth.login.decay_minutes', 15) * 60);
        $attempts = $this->limiter->peek('login:' . $ip)['count'];
        $out = ['ok' => false, 'reason' => $reason];
        if ($attempts >= $this->config->int('auth.login.max_attempts', 5)) {
            $out['locked'] = true;
            $out['retry_after'] = $this->throttleRetryAfter($ip, $identifier);
        }

        return $out;
    }

    private function registerFailure(User $user): void
    {
        $max = $this->config->int('auth.login.max_attempts', 5);
        $attempts = $user->failedAttempts + 1;
        $update = ['failed_attempts' => $attempts, 'updated_at' => $this->clock->format()];
        if ($attempts >= $max) {
            $minutes = $this->config->int('auth.login.lockout_minutes', 30);
            $update['locked_until'] = $this->clock->format('Y-m-d H:i:s', $this->clock->now() + $minutes * 60);
            $update['failed_attempts'] = 0;
            $this->logger->channel('audit')->warning('Учётная запись заблокирована после неудачных попыток', [
                'action' => 'account_locked', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
                'subject_label' => $user->login, 'is_security' => true, 'attempts' => $attempts, 'minutes' => $minutes,
            ]);
        }
        $this->db->table('users')->where('id', '=', $user->id)->update($update);
    }

    private function recordAttempt(string $identifier, string $ip, string $ua, bool $ok, ?int $userId, ?string $reason, string $method = 'password'): void
    {
        try {
            $this->db->table('login_attempts')->insert([
                'identifier' => mb_substr($identifier, 0, 190),
                'ip'         => $ip,
                'user_agent' => mb_substr($ua, 0, 512),
                'successful' => $ok ? 1 : 0,
                'user_id'    => $userId,
                'reason'     => $reason,
                'method'     => $method,
                'created_at' => $this->clock->format(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Не удалось записать попытку входа: ' . $e->getMessage());
        }
    }

    private function isThrottled(string $ip, string $identifier): bool
    {
        $max = $this->config->int('auth.login.max_attempts', 5);
        $window = $this->config->int('auth.login.decay_minutes', 15) * 60;

        return $this->limiter->tooManyAttempts('login:' . $ip, $max, $window)
            || $this->limiter->tooManyAttempts('login-id:' . mb_strtolower($identifier), $max, $window);
    }

    private function throttleRetryAfter(string $ip, string $identifier): int
    {
        $window = $this->config->int('auth.login.decay_minutes', 15) * 60;
        $byIp = $this->limiter->peek('login:' . $ip);
        $byId = $this->limiter->peek('login-id:' . mb_strtolower($identifier));

        return max(0, max($byIp['reset'], $byId['reset']) - $this->clock->now()) ?: $window;
    }

    /** Waste comparable CPU time so unknown accounts are not detectable by timing. */
    private function dummyVerify(string $password): void
    {
        $hash = '$2y$12$C6UzMDM.H6dfI/f/IKcEeO7ZBpDLmLzXbqJQ8vF3nS8ZqZ1vQ1bOe';
        password_verify($password, $hash);
    }

    /** Look a user up by login or e-mail (delegates to the guard). */
    public function findByLogin(string $identifier): ?User
    {
        return $this->guard->findByLogin($identifier);
    }

    public function findById(int $id): ?User
    {
        return $this->guard->findById($id);
    }

    /* ─────────────────────────── passwords ─────────────────────────── */

    public function hashPassword(string $password): string
    {
        $algo = $this->config->string('auth.password.algo', 'auto');
        $options = match ($algo) {
            'argon2id' => defined('PASSWORD_ARGON2ID') ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1] : [],
            'argon2i'  => defined('PASSWORD_ARGON2I') ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1] : [],
            'bcrypt'   => ['cost' => 12],
            default    => defined('PASSWORD_ARGON2ID') ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1] : ['cost' => 12],
        };
        $resolved = match ($algo) {
            'argon2id' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
            'argon2i'  => defined('PASSWORD_ARGON2I') ? PASSWORD_ARGON2I : PASSWORD_BCRYPT,
            'bcrypt'   => PASSWORD_BCRYPT,
            default    => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
        };

        $hash = password_hash($password, $resolved, $options);
        if (!is_string($hash)) {
            throw new RuntimeException('Не удалось хешировать пароль.');
        }

        return $hash;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '' || $password === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $info = password_get_info($hash);
        if (($info['algo'] ?? null) === null || ($info['algoName'] ?? 'unknown') === 'unknown') {
            return true;
        }

        return password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * Password policy check.
     *
     * @return array<int, string> list of human-readable violations (empty = OK)
     */
    public function validatePassword(string $password, ?string $login = null): array
    {
        $errors = [];
        $min = $this->config->int('auth.password.min_length', 10);

        if (mb_strlen($password) < $min) {
            $errors[] = sprintf('Минимальная длина пароля — %d символов.', $min);
        }
        if ($this->config->bool('auth.password.require_mixed_case', true) && (!preg_match('/[a-zа-я]/u', $password) || !preg_match('/[A-ZА-Я]/u', $password))) {
            $errors[] = 'Пароль должен содержать строчные и прописные буквы.';
        }
        if ($this->config->bool('auth.password.require_digit', true) && !preg_match('/\d/', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы одну цифру.';
        }
        if ($this->config->bool('auth.password.require_symbol', false) && !preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы один спецсимвол.';
        }
        if ($login !== null && $login !== '' && Str::lower($password) === Str::lower($login)) {
            $errors[] = 'Пароль не должен совпадать с логином.';
        }
        foreach (['password', '12345678', 'qwerty123', 'admin123', 'basip2026'] as $weak) {
            if (Str::lower($password) === $weak) {
                $errors[] = 'Пароль слишком простой.';
                break;
            }
        }
        if ($this->config->bool('auth.password.breach_check', false) && $this->isBreached($password)) {
            $errors[] = 'Этот пароль встречается в базах утечек. Выберите другой.';
        }

        return $errors;
    }

    /** k-anonymity lookup against the HIBP Passwords range API (opt-in). */
    public function isBreached(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $context = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: BAS-IP-Gate-Manager\r\n"]]);
        $body = @file_get_contents('https://api.pwnedpasswords.com/range/' . $prefix, false, $context);
        if (!is_string($body) || $body === '') {
            return false;   // fail open — never block a login because a third party is down
        }

        return stripos($body, substr($sha1, 5)) !== false;
    }

    public function setPassword(int $userId, string $password, bool $mustChange = false): void
    {
        $this->db->table('users')->where('id', '=', $userId)->update([
            'password_hash' => $this->hashPassword($password),
            'password_changed_at' => $this->clock->format(),
            'must_change_password' => $mustChange ? 1 : 0,
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => $this->clock->format(),
        ]);
    }

    /** @return array{ok: bool, error?: string} */
    public function changePassword(User $user, string $current, string $new, bool $logoutOthers = true): array
    {
        if (!$this->verifyPassword($current, $user->passwordHash)) {
            return ['ok' => false, 'error' => 'Текущий пароль указан неверно.'];
        }
        $errors = $this->validatePassword($new, $user->login);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        if ($this->verifyPassword($new, $user->passwordHash)) {
            return ['ok' => false, 'error' => 'Новый пароль совпадает с текущим.'];
        }

        $this->setPassword($user->id, $new);
        if ($logoutOthers) {
            $this->guard->logoutOtherSessions($user->id, $this->guard->currentSessionHash());
        }
        $this->logger->channel('audit')->info('Пароль изменён', [
            'action' => 'password_changed', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'subject_label' => $user->login, 'actor_name' => $user->login, 'is_security' => true,
        ]);

        return ['ok' => true];
    }

    /* ─────────────────────────── 2FA ─────────────────────────── */

    public function totp(): Totp
    {
        return new Totp(
            issuer: $this->config->string('auth.two_factor.issuer', 'BAS-IP'),
            digits: $this->config->int('auth.two_factor.digits', 6),
            period: $this->config->int('auth.two_factor.period', 30),
            algorithm: $this->config->string('auth.two_factor.algorithm', 'sha1'),
            window: $this->config->int('auth.two_factor.window', 1),
        );
    }

    /** @return array{secret: string, uri: string} */
    public function beginTwoFactorEnrolment(User $user): array
    {
        $secret = Totp::generateSecret();
        $this->session->set('2fa_pending_secret', $secret);

        return [
            'secret' => $secret,
            'uri'    => $this->totp()->provisioningUri($secret, $user->login),
        ];
    }

    /** @return array{ok: bool, recovery_codes?: array<int, string>, error?: string} */
    public function confirmTwoFactorEnrolment(User $user, string $code): array
    {
        $secret = (string) $this->session->get('2fa_pending_secret', '');
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Сначала запросите новый ключ 2FA.'];
        }
        if (!$this->totp()->verify($secret, $code)) {
            return ['ok' => false, 'error' => 'Код не подошёл. Проверьте время на устройстве.'];
        }

        $codes = $this->generateRecoveryCodes();
        $this->db->table('users')->where('id', '=', $user->id)->update([
            'totp_secret'  => $this->crypto->encrypt($secret, 'totp'),
            'totp_enabled' => 1,
            'recovery_codes' => $this->crypto->encrypt((string) json_encode(array_map(
                static fn (string $c): string => hash('sha256', $c),
                $codes
            )), 'recovery'),
            'updated_at' => $this->clock->format(),
        ]);
        $this->session->forget('2fa_pending_secret');

        $this->logger->channel('audit')->info('2FA включена', [
            'action' => '2fa_enabled', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'subject_label' => $user->login, 'is_security' => true,
        ]);

        return ['ok' => true, 'recovery_codes' => $codes];
    }

    public function disableTwoFactor(User $user, string $password): bool
    {
        if (!$this->verifyPassword($password, $user->passwordHash)) {
            return false;
        }
        $this->db->table('users')->where('id', '=', $user->id)->update([
            'totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null,
            'updated_at' => $this->clock->format(),
        ]);
        $this->logger->channel('audit')->warning('2FA отключена', [
            'action' => '2fa_disabled', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'subject_label' => $user->login, 'is_security' => true,
        ]);

        return true;
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $count = max(1, $count ?: $this->config->int('auth.two_factor.recovery_codes', 8));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::friendlyCode(2, 5, '23456789ABCDEFGHJKLMNPQRSTUVWXYZ');
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $encrypted = $this->db->table('users')->where('id', '=', $user->id)->value('recovery_codes');
        if (!is_string($encrypted) || $encrypted === '') {
            return false;
        }
        $json = $this->crypto->decrypt($encrypted, 'recovery');
        $hashes = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        $needle = hash('sha256', $normalized);
        $index = array_search($needle, $hashes, true);
        if ($index === false) {
            // Also accept the dashed form.
            $needle = hash('sha256', strtoupper($code));
            $index = array_search($needle, $hashes, true);
        }
        if ($index === false) {
            return false;
        }
        unset($hashes[$index]);
        $this->db->table('users')->where('id', '=', $user->id)->update([
            'recovery_codes' => $this->crypto->encrypt((string) json_encode(array_values($hashes)), 'recovery'),
            'updated_at' => $this->clock->format(),
        ]);

        return true;
    }

    /* ─────────────────────────── password reset ─────────────────────────── */

    /**
     * Issue a single-use reset token. The caller decides how to deliver it
     * (e-mail via the notification service, or printed by an admin / CLI).
     */
    public function createResetToken(string $identifier): ?array
    {
        $user = $this->guard->findByLogin($identifier);
        if ($user === null || !$user->isActive) {
            return null;
        }
        $plain = bin2hex(random_bytes(24));
        $this->db->table('users')->where('id', '=', $user->id)->update([
            'remember_token' => hash_hmac('sha256', $plain, (string) $this->config->get('app.key', 'bas-ip') . ':reset'),
            'updated_at' => $this->clock->format(),
        ]);
        $this->session->set('reset_token_' . $user->id, $this->clock->now());

        return ['user' => $user, 'token' => $plain, 'expires_in' => 3600];
    }

    public function resetPassword(string $identifier, string $token, string $newPassword): array
    {
        $user = $this->guard->findByLogin($identifier);
        if ($user === null) {
            return ['ok' => false, 'error' => 'Ссылка недействительна или устарела.'];
        }
        $expected = (string) $this->db->table('users')->where('id', '=', $user->id)->value('remember_token', '');
        $provided = hash_hmac('sha256', $token, (string) $this->config->get('app.key', 'bas-ip') . ':reset');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return ['ok' => false, 'error' => 'Ссылка недействительна или устарела.'];
        }
        $errors = $this->validatePassword($newPassword, $user->login);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $this->setPassword($user->id, $newPassword);
        $this->db->table('users')->where('id', '=', $user->id)->update(['remember_token' => null]);
        $this->guard->logoutOtherSessions($user->id);

        $this->logger->channel('audit')->info('Пароль сброшен по ссылке', [
            'action' => 'password_reset', 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'subject_label' => $user->login, 'is_security' => true,
        ]);

        return ['ok' => true, 'user' => $user];
    }

    /* ─────────────────────────── accounts ─────────────────────────── */

    /** @param array<string, mixed> $data @return array{ok: bool, id?: int, error?: string} */
    public function createAccount(array $data): array
    {
        $login = trim((string) ($data['login'] ?? ''));
        if ($login === '' || !preg_match('/^[A-Za-z0-9._@-]{3,64}$/u', $login)) {
            return ['ok' => false, 'error' => 'Логин: 3–64 символа, латиница, цифры, . _ - @'];
        }
        if ($this->guard->findByLogin($login) !== null) {
            return ['ok' => false, 'error' => 'Такой логин или e-mail уже используется.'];
        }
        $role = (string) ($data['role'] ?? $this->config->string('auth.default_role', 'resident'));
        if (!Permissions::isValidRole($role)) {
            return ['ok' => false, 'error' => 'Неизвестная роль: ' . $role];
        }
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = $this->generatePassword();
        }
        $errors = $this->validatePassword($password, $login);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $now = $this->clock->format();
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '') {
            $normalized = \BasIp\Support\Phone::normalize($phone);
            $phone = $normalized !== '' ? $normalized : mb_substr($phone, 0, 32);
        }
        $id = $this->db->table('users')->insertGetId([
            'uuid'         => Crypto::uuidv4(),
            'login'        => $login,
            'email'        => $data['email'] ?? null,
            'phone'        => $phone !== '' ? $phone : null,
            'password_hash' => $this->hashPassword($password),
            'full_name'    => trim((string) ($data['full_name'] ?? '')) ?: $login,
            'role'         => $role,
            'resident_id'  => $data['resident_id'] ?? null,
            'is_active'    => (int) (bool) ($data['is_active'] ?? true),
            'must_change_password' => (int) (bool) ($data['must_change_password'] ?? true),
            'locale'       => $data['locale'] ?? null,
            'password_changed_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return ['ok' => true, 'id' => $id, 'password' => $password, 'generated' => ($data['password'] ?? '') === ''];
    }

    public function generatePassword(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
        do {
            $password = Str::random($length, $alphabet);
        } while (count($this->validatePassword($password)) > 0);

        return $password;
    }

    /** @return array<int, array<string, mixed>> */
    public function activeSessions(int $userId): array
    {
        $rows = $this->db->table('sessions_meta')
            ->where('user_id', '=', $userId)
            ->orderBy('last_used_at', 'desc')
            ->get();

        return array_map(static function (array $row): array {
            $row['is_current'] = false;

            return $row;
        }, $rows);
    }

    /** Purge expired session metadata (cron). */
    public function gcSessions(): int
    {
        return $this->db->table('sessions_meta')
            ->where('expires_at', '<', $this->clock->format())
            ->orWhere('last_used_at', '<', $this->clock->format('Y-m-d H:i:s', $this->clock->now() - 86400 * 30))
            ->delete();
    }

    /** Prune old login attempts (cron). */
    public function gcLoginAttempts(int $keepDays = 90): int
    {
        return $this->db->table('login_attempts')
            ->where('created_at', '<', $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $keepDays * 86400))
            ->delete();
    }

    /**
     * Lift a lockout.
     *
     * Clears every throttle bucket keyed to this account — the login *and* the
     * e-mail, since `attempt()` throttles per identifier. (The per-IP bucket is
     * left alone on purpose: it protects against credential stuffing from one
     * host and decays on its own.)
     */
    public function unlock(int $userId): void
    {
        $user = $this->guard->findById($userId);
        $this->db->table('users')->where('id', '=', $userId)->update([
            'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => $this->clock->format(),
        ]);

        $this->limiter->clear('login:' . $userId);
        if ($user !== null) {
            $this->limiter->clear('login-id:' . Str::lower($user->login));
            if ($user->email !== null && $user->email !== '') {
                $this->limiter->clear('login-id:' . Str::lower($user->email));
            }
        }
    }
}
