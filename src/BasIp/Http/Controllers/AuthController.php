<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Auth\AuthService;
use BasIp\Auth\Permissions;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Http\RoutingException;
use BasIp\Notify\Message;
use BasIp\Notify\NotificationService;
use BasIp\Services\UserService;
use BasIp\Support\Ip;

/**
 * Login, two-factor challenge, password recovery, profile and preferences.
 */
final class AuthController extends Controller
{
    /* ─────────────────────────── login ─────────────────────────── */

    public function showLogin(Request $request): Response
    {
        if ($this->auth()->check()) {
            return $this->redirect($this->landingFor($this->requireUser()));
        }
        if ($this->isInstalled() === false) {
            return $this->redirect('/install');
        }

        $attempts = (int) $this->session()->get('login_attempts', 0);
        $captchaAfter = $this->config()->int('auth.login.captcha_after', 3);

        return $this->view('auth/login', [
            'title'      => 'Вход',
            'layout'     => 'auth',
            'attempts'   => $attempts,
            'showCaptcha' => $captchaAfter > 0 && $attempts >= $captchaAfter,
            'captcha'    => $this->makeCaptcha(),
            'rememberEnabled' => $this->config()->bool('auth.session.remember_me.enabled', true),
            'twoFactorEnabled' => $this->config()->bool('auth.two_factor.enabled', true),
            'allowRegistration' => $this->config()->bool('auth.allow_registration', false),
            'locked'     => $this->session()->get('login_locked_until'),
        ]);
    }

    public function login(Request $request): Response
    {
        if ($this->auth()->check()) {
            return $this->redirect($this->landingFor($this->requireUser()));
        }

        $lockedUntil = (int) $this->session()->get('login_locked_until', 0);
        if ($lockedUntil > time()) {
            return $this->loginFailure($request, sprintf('Слишком много попыток. Повторите через %d мин.', (int) ceil(($lockedUntil - time()) / 60)), 429);
        }

        $identifier = trim($request->str('login'));
        $password   = (string) $request->input('password', '');
        $remember   = $request->bool('remember');

        if ($identifier === '' || $password === '') {
            return $this->loginFailure($request, 'Введите логин и пароль.');
        }

        // Optional simple captcha after N failures.
        $captchaAfter = $this->config()->int('auth.login.captcha_after', 3);
        $attempts = (int) $this->session()->get('login_attempts', 0);
        if ($captchaAfter > 0 && $attempts >= $captchaAfter) {
            $expected = (string) $this->session()->get('captcha_answer', '');
            if ($expected === '' || !hash_equals($expected, trim($request->str('captcha')))) {
                return $this->loginFailure($request, 'Неверный ответ на защитный вопрос.', false);
            }
        }

        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $result = $auth->attempt($identifier, $password, $request, $remember);

        if (!empty($result['requires_2fa'])) {
            $this->session()->set('login_attempts', 0);

            return $request->expectsJson()
                ? $this->json(['ok' => false, 'requires_2fa' => true, 'redirect' => $request->url('/two-factor')])
                : $this->redirect('/two-factor');
        }

        if (empty($result['ok'])) {
            return $this->loginFailure($request, (string) ($result['reason'] ?? 'Не удалось войти.'), true, (int) ($result['retry_after'] ?? 0));
        }

        $user = $result['user'];
        $this->session()->set('login_attempts', 0);
        $this->session()->forget('login_locked_until');
        $this->session()->forget('captcha_answer');

        $intended = (string) $this->session()->pull('intended_url', '');
        $target = $intended !== '' && $this->isSafeRedirect($intended, $request)
            ? $intended
            : $request->url($this->landingFor($user));

        if ($user->mustChangePassword) {
            $this->session()->set('intended_url', $target);
            $this->flashInfo('Смените пароль, чтобы продолжить работу.');

            return $request->expectsJson()
                ? $this->json(['ok' => true, 'must_change_password' => true, 'redirect' => $request->url('/profile?tab=security')])
                : $this->redirect('/profile?tab=security&force=1');
        }

        return $request->expectsJson()
            ? $this->json(['ok' => true, 'redirect' => $target, 'user' => $user])
            : $this->redirect($target);
    }

    private function loginFailure(Request $request, string $message, bool $countAttempt = true, int $retryAfter = 0): Response
    {
        if ($countAttempt) {
            $attempts = (int) $this->session()->get('login_attempts', 0) + 1;
            $this->session()->set('login_attempts', $attempts);
            if ($retryAfter > 0 || $attempts >= $this->config()->int('auth.login.max_attempts', 5)) {
                $this->session()->set('login_locked_until', time() + max($retryAfter, $this->config()->int('auth.login.lockout_minutes', 30) * 60));
            }
        }
        $this->session()->set('captcha_answer', $this->makeCaptcha()['answer']);
        $this->flashError($message);

        if ($request->expectsJson()) {
            return $this->jsonError($message, $retryAfter > 0 ? 429 : 401, ['retry_after' => $retryAfter]);
        }

        return $this->redirect('/login');
    }

    /** @return array{question: string, answer: string} */
    private function makeCaptcha(): array
    {
        $a = random_int(3, 12);
        $b = random_int(2, 9);
        $answer = (string) ($a + $b);
        $this->session()->set('captcha_answer', $answer);

        return ['question' => sprintf('%d + %d = ?', $a, $b), 'answer' => $answer];
    }

    private function isSafeRedirect(string $url, Request $request): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host !== '' && $host === ($request->header('host') ?? '');
    }

    /* ─────────────────────────── 2FA challenge ─────────────────────────── */

    public function showTwoFactor(Request $request): Response
    {
        if ($this->session()->get('pending_2fa_user_id') === null) {
            return $this->redirect('/login');
        }

        return $this->view('auth/two-factor', ['title' => 'Двухфакторная аутентификация', 'layout' => 'auth']);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $result = $auth->verifyTwoFactor(trim($request->str('code')), $request);

        if (empty($result['ok'])) {
            $this->flashError((string) ($result['reason'] ?? 'Неверный код.'));

            return $request->expectsJson()
                ? $this->jsonError((string) ($result['reason'] ?? 'Неверный код.'), 401)
                : $this->redirect('/two-factor');
        }

        $user = $result['user'];
        $intended = (string) $this->session()->pull('intended_url', '');

        return $request->expectsJson()
            ? $this->json(['ok' => true, 'redirect' => $intended !== '' ? $intended : $request->url($this->landingFor($user))])
            : $this->redirect($intended !== '' && $this->isSafeRedirect($intended, $request) ? $intended : $this->landingFor($user));
    }

    public function logout(Request $request): Response
    {
        $user = $this->user();
        if ($user !== null) {
            $this->auditService()->info('logout', $user, 'user', (string) $user->id, $user->login, $request);
        }
        $this->auth()->logout($request);
        $this->session()->destroy();

        return $request->expectsJson()
            ? $this->json(['ok' => true, 'redirect' => $request->url('/login')])
            : $this->redirect('/login')->withHeaders(['Cache-Control' => 'no-store']);
    }

    /* ─────────────────────────── password recovery ─────────────────────────── */

    public function showForgot(Request $request): Response
    {
        return $this->view('auth/forgot', ['title' => 'Восстановление пароля', 'layout' => 'auth']);
    }

    public function forgot(Request $request): Response
    {
        $identifier = trim($request->str('login'));
        $generic = 'Если такая учётная запись существует, ссылка для восстановления отправлена.';

        if ($identifier === '') {
            $this->flashError('Укажите логин или e-mail.');

            return $this->redirect('/password/forgot');
        }

        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $token = $auth->createResetToken($identifier);

        if ($token !== null) {
            $link = rtrim($this->config()->string('app.url', ''), '/') . $request->url('/password/reset/' . rawurlencode((string) $token['token']));
            /** @var NotificationService $notify */
            $notify = $this->svc(NotificationService::class);
            $notify->queue(Message::make()
                ->channel('mail')
                ->to((string) ($token['user']->email ?? ''))
                ->template('password_reset')
                ->subject('Восстановление доступа к ' . $this->config()->string('app.name'))
                ->body(sprintf(
                    "Здравствуйте, %s!\n\nКто-то запросил восстановление пароля для учётной записи %s.\n\nСсылка (действует 1 час): %s\n\nЕсли вы не запрашивали сброс — просто проигнорируйте это письмо.",
                    $token['user']->displayName(), $token['user']->login, $link
                )));
            $this->auditService()->security('password.reset_requested', $token['user'], ['subject_type' => 'user', 'subject_id' => (string) $token['user']->id], $request);
        }

        $this->flashInfo($generic);

        return $this->redirect('/login');
    }

    public function showReset(Request $request, string $token = ''): Response
    {
        return $this->view('auth/reset', ['title' => 'Новый пароль', 'layout' => 'auth', 'token' => $token]);
    }

    public function reset(Request $request, string $token = ''): Response
    {
        $identifier = trim($request->str('login'));
        $password   = (string) $request->input('password', '');
        $confirm    = (string) $request->input('password_confirm', '');

        if ($password !== $confirm) {
            $this->flashError('Пароли не совпадают.');

            return $this->redirect('/password/reset/' . rawurlencode($token) . '?login=' . rawurlencode($identifier));
        }

        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $result = $auth->resetPassword($identifier, $token, $password);

        if (empty($result['ok'])) {
            $this->flashError((string) ($result['error'] ?? 'Не удалось сбросить пароль.'));

            return $this->redirect('/password/forgot');
        }

        $this->flashSuccess('Пароль изменён. Войдите с новым паролем.');

        return $this->redirect('/login');
    }

    /* ─────────────────────────── profile ─────────────────────────── */

    public function showProfile(Request $request): Response
    {
        $user = $this->requireUser();

        return $this->view('auth/profile', [
            'title'    => 'Профиль и безопасность',
            'tab'      => $request->str('tab', 'profile'),
            'force'    => $request->bool('force') || $user->mustChangePassword,
            'sessions' => $this->svc(UserService::class)->activeSessions($user->id),
            'logins'   => $this->db()->table('login_attempts')->where('user_id', '=', $user->id)->orderBy('id', 'desc')->limit(15)->get(),
            'twoFactor' => $this->config()->bool('auth.two_factor.enabled', true),
            'permissions' => Permissions::forRole($user->role),
            'permissionLabels' => Permissions::labels(),
        ]);
    }

    public function saveProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $data = $this->validate($request, [
            'full_name' => 'required|max:190',
            'email'     => 'nullable|email|max:190',
            'locale'    => 'nullable|in:ru,en',
        ], ['full_name' => 'Имя', 'email' => 'E-mail']);

        $update = ['full_name' => $data['full_name'], 'updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('email', $data)) {
            $email = $data['email'] !== '' ? $data['email'] : null;
            $taken = $this->db()->table('users')->where('email', '=', $email)->where('id', '!=', $user->id)->count();
            if ($email !== null && $taken > 0) {
                $this->flashError('Этот e-mail уже используется другой учётной записью.');

                return $this->redirect('/profile');
            }
            $update['email'] = $email;
        }
        if (!empty($data['locale'])) {
            $update['locale'] = $data['locale'];
        }

        $this->db()->table('users')->where('id', '=', $user->id)->update($update);

        if (array_key_exists('theme', $request->all()) || array_key_exists('density', $request->all()) || array_key_exists('per_page', $request->all())) {
            $this->svc(UserService::class)->savePreferences($user->id, $request->only(['theme', 'density', 'accent', 'per_page', 'sidebar_collapsed', 'default_landing']));
        }

        $this->auditService()->changed('profile.update', $user, 'user', (string) $user->id, $user->login, $user->jsonSerialize(), $update, $request);
        $this->flashSuccess('Профиль сохранён.');

        return $this->redirect('/profile');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->requireUser();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirm', '');

        if ($new !== $confirm) {
            $this->flashError('Новый пароль и подтверждение не совпадают.');

            return $this->redirect('/profile?tab=security');
        }

        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $result = $auth->changePassword($user, $current, $new, $request->bool('logout_others', true));

        if (empty($result['ok'])) {
            $this->flashError((string) ($result['error'] ?? 'Не удалось сменить пароль.'));

            return $this->redirect('/profile?tab=security');
        }

        $this->session()->forget('intended_url');
        $this->flashSuccess('Пароль изменён. Остальные сессии завершены.');

        return $this->redirect('/profile?tab=security');
    }

    /** Theme preference is stored both server-side and in a cookie (for the pre-paint script). */
    public function saveTheme(Request $request): Response
    {
        $theme = strtolower($request->str('theme', 'system'));
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $theme = 'system';
        }

        $user = $this->user();
        if ($user !== null) {
            $this->svc(UserService::class)->savePreferences($user->id, ['theme' => $theme]);
        }

        $response = $this->json(['ok' => true, 'theme' => $theme]);

        return $response->cookie('basip_theme', $theme, time() + 86400 * 365, [
            'path' => $request->basePath() !== '' ? $request->basePath() : '/',
            'secure' => $request->isSecure(),
            'httponly' => false,        // the pre-paint script must read it
            'samesite' => 'Lax',
        ]);
    }

    public function sessions(Request $request): Response
    {
        $user = $this->requireUser();
        $sessions = $this->svc(UserService::class)->activeSessions($user->id);
        $current = $this->auth()->currentSessionHash();
        foreach ($sessions as &$session) {
            $session['is_current'] = ($session['session_id'] ?? '') === $current;
        }
        unset($session);

        return $this->view('auth/sessions', ['title' => 'Активные сессии', 'sessions' => $sessions]);
    }

    public function revokeSession(Request $request, string $id = ''): Response
    {
        $user = $this->requireUser();
        $hash = $this->auth()->currentSessionHash();
        if ($id === $hash) {
            $this->flashError('Нельзя завершить текущую сессию — используйте «Выйти».');

            return $this->redirect('/profile/sessions');
        }
        $count = $this->db()->table('sessions_meta')->where('user_id', '=', $user->id)->where('id', '=', (int) $id)->delete();
        $this->auditService()->security('session.revoke', $user, ['subject_type' => 'session', 'subject_id' => $id], $request);

        return $this->respond($request, $count > 0, $count > 0 ? 'Сессия завершена.' : 'Сессия не найдена.', '/profile/sessions');
    }

    /* ─────────────────────────── 2FA enrolment ─────────────────────────── */

    public function twoFactorSetup(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->config()->bool('auth.two_factor.enabled', true)) {
            $this->flashError('Двухфакторная аутентификация отключена администратором.');

            return $this->redirect('/profile?tab=security');
        }

        return $this->view('auth/two-factor-setup', [
            'title' => 'Двухфакторная аутентификация',
            'pending' => (string) $this->session()->get('2fa_pending_secret', ''),
        ]);
    }

    public function twoFactorStart(Request $request): Response
    {
        $user = $this->requireUser();
        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $enrolment = $auth->beginTwoFactorEnrolment($user);

        return $this->json([
            'secret' => $enrolment['secret'],
            'uri'    => $enrolment['uri'],
            'qr'     => $enrolment['uri'],
            'formatted' => trim(chunk_split($enrolment['secret'], 4, ' ')),
        ]);
    }

    public function twoFactorConfirm(Request $request): Response
    {
        $user = $this->requireUser();
        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $result = $auth->confirmTwoFactorEnrolment($user, trim($request->str('code')));

        if (empty($result['ok'])) {
            return $request->expectsJson()
                ? $this->jsonError((string) ($result['error'] ?? 'Ошибка'))
                : $this->withFlashError((string) ($result['error'] ?? 'Ошибка'), '/profile/2fa');
        }

        $this->flashSuccess('Двухфакторная аутентификация включена. Сохраните резервные коды!');
        $this->session()->set('recovery_codes', $result['recovery_codes']);

        return $request->expectsJson()
            ? $this->json(['ok' => true, 'recovery_codes' => $result['recovery_codes']])
            : $this->redirect('/profile?tab=security&codes=1');
    }

    public function twoFactorDisable(Request $request): Response
    {
        $user = $this->requireUser();
        if (in_array($user->role, (array) $this->config()->get('auth.two_factor.required_for', []), true)) {
            return $this->respond($request, false, '2FA обязательна для вашей роли и не может быть отключена.', '/profile?tab=security');
        }

        /** @var AuthService $auth */
        $auth = $this->svc(AuthService::class);
        $ok = $auth->disableTwoFactor($user, (string) $request->input('password', ''));

        return $this->respond($request, $ok, $ok ? '2FA отключена.' : 'Неверный пароль.', '/profile?tab=security');
    }

    private function withFlashError(string $message, string $to): Response
    {
        $this->flashError($message);

        return $this->redirect($to);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function landingFor(\BasIp\Auth\User $user): string
    {
        $preferred = (string) ($user->preference('default_landing', '') ?: '');
        if ($preferred !== '' && str_starts_with($preferred, '/')) {
            return $preferred;
        }

        return match ($user->role) {
            'admin'    => '/admin',
            'operator' => '/admin',
            'resident' => '/cabinet',
            default    => '/panel',
        };
    }

    private function isInstalled(): bool
    {
        return is_file($this->config()->getPath('public') . '/install.lock')
            || $this->db()->tableExists('users');
    }

    public static function anonymizeIp(string $ip): string
    {
        return Ip::anonymize($ip);
    }
}
