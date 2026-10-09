<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Auth\Permissions;
use BasIp\Auth\User;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Services\UserService;

/**
 * Account administration: staff and resident logins, roles, 2FA, lockouts and
 * active sessions.
 */
final class UsersController extends Controller
{
    private function users(): UserService
    {
        return $this->svc(UserService::class);
    }

    public function index(Request $request): Response
    {
        $this->requirePermission('users.view');

        $filters = $this->filters($request, ['q', 'role', 'is_active', 'two_factor']);
        $paginator = $this->users()->search($filters, $this->perPage($request), $this->page($request));

        return $this->view('admin/users/index', [
            'title'   => 'Пользователи',
            'users'   => $paginator,
            'filters' => $filters,
            'query'   => $request->query,
            'stats'   => $this->users()->stats(),
            'roles'   => ['admin' => 'Администратор', 'operator' => 'Диспетчер', 'resident' => 'Житель'],
            'permissions' => Permissions::ALL,
            'permissionLabels' => Permissions::labels(),
            'matrix'  => $this->permissionMatrix(),
            'residents' => $this->db()->table('residents')->select('id', 'full_name', 'apartment')->whereNull('deleted_at')->orderBy('apartment_no')->limit(3000)->get(),
            'canEdit' => $this->requireUser()->can('users.edit'),
            'canCreate' => $this->requireUser()->can('users.create'),
            'logins'  => $this->users()->recentLogins(10),
        ]);
    }

    public function show(Request $request, string $id = ''): Response
    {
        $this->requirePermission('users.view');
        $user = $this->users()->find((int) $id);
        if ($user === null) {
            $this->notFound('Учётная запись не найдена.');
        }

        $sessions = $this->users()->activeSessions($user->id);
        $current = $this->auth()->currentSessionHash();
        foreach ($sessions as &$session) {
            $session['is_current'] = ($session['session_id'] ?? '') === $current;
        }
        unset($session);

        return $this->view('admin/users/show', [
            'title'    => 'Учётная запись: ' . $user->login,
            'account'  => $user,
            'sessions' => $sessions,
            'logins'   => $this->db()->table('login_attempts')->where('user_id', '=', $user->id)->orderBy('id', 'desc')->limit(50)->get(),
            'audit'    => $this->auditService()->forSubject('user', $user->id, 50),
            'resident' => $user->residentId !== null ? $this->residentRepo()->findById($user->residentId) : null,
            'permissions' => Permissions::forRole($user->role),
            'permissionLabels' => Permissions::labels(),
            'roles'    => ['admin' => 'Администратор', 'operator' => 'Диспетчер', 'resident' => 'Житель'],
            'canEdit'  => $this->requireUser()->can('users.edit'),
        ]);
    }

    /** @return array<string, array<string, bool>> */
    private function permissionMatrix(): array
    {
        $matrix = [];
        foreach (Permissions::roles() as $role) {
            foreach (Permissions::ALL as $permission) {
                $matrix[$permission][$role] = Permissions::can($role, $permission);
            }
        }

        return $matrix;
    }

    public function store(Request $request): Response
    {
        $actor = $this->requirePermission('users.create');

        $data = $this->validate($request, [
            'login'     => 'required|min:3|max:64|alpha_dash',
            'email'     => 'nullable|email|max:190',
            'full_name' => 'required|max:190',
            'role'      => 'required|in:admin,operator,resident',
            'password'  => 'nullable|min:10',
        ], ['login' => 'Логин', 'full_name' => 'ФИО', 'role' => 'Роль', 'password' => 'Пароль']);

        $result = $this->users()->create([
            'login'     => $data['login'],
            'email'     => $data['email'] ?? null,
            'full_name' => $data['full_name'],
            'role'      => $data['role'],
            'password'  => (string) ($request->input('password') ?? ''),
            'resident_id' => $request->filled('resident_id') ? $request->int('resident_id') : null,
            'is_active' => $request->bool('is_active', true),
            'must_change_password' => $request->bool('must_change_password', true),
            'locale'    => $request->str('locale') ?: null,
        ], $actor, $request);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка'), '/admin/users');
        }

        $this->flashSuccess(sprintf(
            'Учётная запись «%s» создана.%s',
            $data['login'],
            !empty($result['password']) ? ' Сгенерированный пароль: ' . $result['password'] : ''
        ));

        return $this->redirect('/admin/users/' . (int) $result['id']);
    }

    public function update(Request $request, string $id = ''): Response
    {
        $actor = $this->requirePermission('users.edit');
        $result = $this->users()->update((int) $id, $request->only([
            'full_name', 'email', 'phone', 'role', 'resident_id', 'is_active',
            'must_change_password', 'locale', 'password', 'keep_resident_link',
        ]), $actor, $request);

        return $this->respond($request, (bool) $result['ok'], (string) ($result['ok'] ? 'Сохранено.' : ($result['error'] ?? 'Ошибка')), '/admin/users/' . (int) $id);
    }

    public function destroy(Request $request, string $id = ''): Response
    {
        $actor = $this->requirePermission('users.delete');
        $result = $this->users()->delete((int) $id, $actor, $request);

        return $this->respond($request, (bool) $result['ok'], (string) ($result['ok'] ? 'Учётная запись удалена.' : ($result['error'] ?? 'Ошибка')), '/admin/users');
    }

    public function unlock(Request $request, string $id = ''): Response
    {
        $actor = $this->requirePermission('users.edit');
        $this->svc(\BasIp\Auth\AuthService::class)->unlock((int) $id);
        $this->auditService()->info('user.unlock', $actor, 'user', (string) $id, null, $request);

        return $this->respond($request, true, 'Блокировка снята.', '/admin/users/' . (int) $id);
    }

    public function resetTwoFactor(Request $request, string $id = ''): Response
    {
        $actor = $this->requirePermission('users.edit');
        $target = $this->users()->find((int) $id);
        if ($target === null) {
            return $this->respond($request, false, 'Учётная запись не найдена.', '/admin/users');
        }

        $this->db()->table('users')->where('id', '=', $target->id)->update([
            'totp_enabled' => 0, 'totp_secret' => null, 'recovery_codes' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->auth()->logoutOtherSessions($target->id);
        $this->auditService()->security('user.2fa_reset', $actor, ['subject_type' => 'user', 'subject_id' => (string) $target->id, 'subject_label' => $target->login], $request);

        return $this->respond($request, true, '2FA сброшена — пользователь настроит её заново при входе.', '/admin/users/' . (int) $id);
    }

    public function logoutSessions(Request $request, string $id = ''): Response
    {
        $actor = $this->requirePermission('users.edit');
        $count = $this->auth()->logoutOtherSessions((int) $id);
        $this->db()->table('sessions_meta')->where('user_id', '=', (int) $id)->delete();
        $this->auditService()->security('user.logout_all', $actor, ['subject_type' => 'user', 'subject_id' => (string) $id, 'meta' => ['sessions' => $count]], $request);

        return $this->respond($request, true, 'Все сессии пользователя завершены.', '/admin/users/' . (int) $id);
    }

    public function sessions(Request $request): Response
    {
        $this->requirePermission('users.view');
        $current = $this->auth()->currentSessionHash();
        $sessions = $this->db()->table('sessions_meta s')
            ->select('s.*', 'u.login', 'u.full_name', 'u.role')
            ->leftJoin('users u', 'u.id', '=', 's.user_id')
            ->orderBy('s.last_used_at', 'desc')
            ->limit(200)
            ->get();
        foreach ($sessions as &$session) {
            $session['is_current'] = ($session['session_id'] ?? '') === $current;
        }
        unset($session);

        return $this->view('admin/users/sessions', ['title' => 'Активные сессии', 'sessions' => $sessions]);
    }

    public function logins(Request $request): Response
    {
        $this->requirePermission('users.view');
        $query = $this->db()->table('login_attempts a')
            ->select('a.*', 'u.login AS user_login')
            ->leftJoin('users u', 'u.id', '=', 'a.user_id');
        if ($request->bool('only_failed')) {
            $query = $query->where('a.successful', '=', 0);
        }
        if ($request->filled('q')) {
            $query = $query->whereSearch(['a.identifier', 'a.ip', 'u.login'], $request->str('q'));
        }
        $paginator = $query->orderBy('a.id', 'desc')->paginate($this->perPage($request, 50), $this->page($request));

        return $this->view('admin/users/logins', [
            'title'   => 'История входов',
            'attempts' => $paginator,
            'query'   => $request->query,
            'stats'   => [
                'total_24h' => $this->db()->table('login_attempts')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
                'failed_24h' => $this->db()->table('login_attempts')->where('successful', '=', 0)->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
                'unique_ips' => count($this->db()->table('login_attempts')->select('ip')->distinct()->where('successful', '=', 0)->pluck('ip')),
            ],
        ]);
    }
}
