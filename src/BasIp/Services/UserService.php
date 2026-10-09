<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\AuthService;
use BasIp\Auth\Guard;
use BasIp\Auth\Permissions;
use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Support\Paginator;
use BasIp\Support\Str;

/**
 * Administration of accounts (admins, operators and resident logins).
 */
final class UserService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
        private readonly Guard $guard,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['role'])) {
            $q = $q->where('role', '=', (string) $filters['role']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q = $q->where('is_active', '=', Str::toBoolSafe($filters['is_active']) ? 1 : 0);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['login', 'full_name', 'email'], (string) $filters['q']);
        }
        if (!empty($filters['two_factor'])) {
            $q = $q->where('totp_enabled', '=', 1);
        }

        return $q->orderBy('role')->orderBy('login')->paginate($perPage, $page);
    }

    public function find(int $id): ?User
    {
        return $this->guard->findById($id);
    }

    /** @return array<int, User> */
    public function admins(): array
    {
        $out = [];
        foreach ($this->db->table('users')->where('role', '=', 'admin')->where('is_active', '=', 1)->whereNull('deleted_at')->get() as $row) {
            $out[] = User::fromRow($row);
        }

        return $out;
    }

    /** @param array<string, mixed> $input @return array{ok: bool, id?: int, password?: string, error?: string} */
    public function create(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $result = $this->auth->createAccount($input);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Не удалось создать учётную запись.'];
        }

        $this->audit->record('user.create', $actor, [
            'subject_type' => 'user', 'subject_id' => (string) $result['id'],
            'subject_label' => (string) ($input['login'] ?? ''),
            'meta' => ['role' => (string) ($input['role'] ?? 'resident')],
            'is_security' => true,
        ], $request);

        return ['ok' => true, 'id' => (int) $result['id'], 'password' => (string) ($result['password'] ?? '')];
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input, ?User $actor = null, ?Request $request = null): array
    {
        $before = $this->db->table('users')->where('id', '=', $id)->first();
        if ($before === null) {
            return ['ok' => false, 'error' => 'Учётная запись не найдена.'];
        }

        $update = ['updated_at' => $this->clock->format()];
        if (array_key_exists('full_name', $input)) {
            $update['full_name'] = mb_substr(Str::squash((string) $input['full_name']), 0, 190);
        }
        if (array_key_exists('email', $input)) {
            $email = trim((string) $input['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'Некорректный e-mail.'];
            }
            $update['email'] = $email !== '' ? mb_strtolower($email) : null;
        }
        if (array_key_exists('phone', $input)) {
            $phone = trim((string) $input['phone']);
            if ($phone !== '') {
                $normalized = \BasIp\Support\Phone::normalize($phone);
                $update['phone'] = mb_substr($normalized !== '' ? $normalized : $phone, 0, 32);
            } else {
                $update['phone'] = null;
            }
        }
        if (array_key_exists('role', $input) && Permissions::isValidRole((string) $input['role'])) {
            if ((string) $input['role'] !== 'resident' && (string) $before['role'] === 'resident' && empty($input['keep_resident_link'])) {
                $update['resident_id'] = null;
            }
            $update['role'] = (string) $input['role'];
        }
        if (array_key_exists('resident_id', $input)) {
            $update['resident_id'] = $input['resident_id'] === '' || $input['resident_id'] === null ? null : (int) $input['resident_id'];
        }
        if (array_key_exists('is_active', $input)) {
            $active = Str::toBoolSafe($input['is_active']);
            if (!$active && $id === $actor?->id) {
                return ['ok' => false, 'error' => 'Нельзя отключить собственную учётную запись.'];
            }
            if (!$active && (string) $before['role'] === 'admin' && $this->countAdmins() <= 1) {
                return ['ok' => false, 'error' => 'Это последний администратор — отключение заблокировано.'];
            }
            $update['is_active'] = $active ? 1 : 0;
        }
        if (array_key_exists('must_change_password', $input)) {
            $update['must_change_password'] = Str::toBoolSafe($input['must_change_password']) ? 1 : 0;
        }
        if (!empty($input['locale'])) {
            $update['locale'] = mb_substr((string) $input['locale'], 0, 8);
        }
        if (!empty($input['password'])) {
            $errors = $this->auth->validatePassword((string) $input['password'], (string) $before['login']);
            if ($errors !== []) {
                return ['ok' => false, 'error' => implode(' ', $errors)];
            }
            $update['password_hash'] = $this->auth->hashPassword((string) $input['password']);
            $update['password_changed_at'] = $this->clock->format();
            $update['must_change_password'] = Str::toBoolSafe($input['must_change_password'] ?? true) ? 1 : 0;
        }

        $this->db->table('users')->where('id', '=', $id)->update($update);

        if (isset($update['is_active']) && $update['is_active'] === 0) {
            $this->guard->logoutOtherSessions($id);
        }

        $this->audit->changed('user.update', $actor, 'user', $id, (string) $before['login'], $before, array_merge($before, $update), $request);

        return ['ok' => true];
    }

    public function delete(int $id, ?User $actor = null, ?Request $request = null): array
    {
        if ($id === $actor?->id) {
            return ['ok' => false, 'error' => 'Нельзя удалить собственную учётную запись.'];
        }
        $row = $this->db->table('users')->where('id', '=', $id)->first();
        if ($row === null) {
            return ['ok' => false, 'error' => 'Учётная запись не найдена.'];
        }
        if ((string) $row['role'] === 'admin' && $this->countAdmins() <= 1) {
            return ['ok' => false, 'error' => 'Это последний администратор.'];
        }

        $this->db->table('users')->where('id', '=', $id)->update([
            'deleted_at' => $this->clock->format(), 'is_active' => 0,
            'remember_token' => null, 'totp_secret' => null, 'totp_enabled' => 0,
        ]);
        $this->guard->logoutOtherSessions($id);
        $this->db->table('sessions_meta')->where('user_id', '=', $id)->delete();

        $this->audit->record('user.delete', $actor, [
            'subject_type' => 'user', 'subject_id' => (string) $id,
            'subject_label' => (string) $row['login'], 'is_security' => true,
        ], $request);

        return ['ok' => true];
    }

    public function restore(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $ok = $this->db->table('users')->where('id', '=', $id)->update([
            'deleted_at' => null, 'updated_at' => $this->clock->format(),
        ]) > 0;
        if ($ok) {
            $this->audit->info('user.restore', $actor, 'user', (string) $id, null, $request);
        }

        return $ok;
    }

    public function countAdmins(): int
    {
        return $this->db->table('users')->where('role', '=', 'admin')->where('is_active', '=', 1)->whereNull('deleted_at')->count();
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'total'    => $this->db->table('users')->whereNull('deleted_at')->count(),
            'admins'   => $this->db->table('users')->where('role', '=', 'admin')->whereNull('deleted_at')->count(),
            'operators' => $this->db->table('users')->where('role', '=', 'operator')->whereNull('deleted_at')->count(),
            'residents' => $this->db->table('users')->where('role', '=', 'resident')->whereNull('deleted_at')->count(),
            'active'   => $this->db->table('users')->where('is_active', '=', 1)->whereNull('deleted_at')->count(),
            'with_2fa' => $this->db->table('users')->where('totp_enabled', '=', 1)->whereNull('deleted_at')->count(),
            'locked'   => $this->db->table('users')->where('locked_until', '>', $this->clock->format())->count(),
            'sessions' => $this->db->table('sessions_meta')->count(),
            'failed_logins_24h' => $this->db->table('login_attempts')
                ->where('successful', '=', 0)
                ->where('created_at', '>=', $this->clock->format('Y-m-d H:i:s', $this->clock->now() - 86400))
                ->count(),
        ];
    }

    /** Save per-user UI preferences (theme, locale, per-page). @param array<string, mixed> $prefs */
    public function savePreferences(int $userId, array $prefs): void
    {
        $current = (array) \BasIp\Domain\Model\Entity::decodeJson($this->db->table('users')->where('id', '=', $userId)->value('preferences'), []);
        $allowed = ['theme', 'accent', 'density', 'locale', 'per_page', 'dashboard_layout', 'sidebar_collapsed', 'default_landing'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $prefs)) {
                $current[$key] = is_scalar($prefs[$key]) ? $prefs[$key] : null;
            }
        }
        $update = [
            'preferences' => (string) json_encode($current, JSON_UNESCAPED_UNICODE),
            'updated_at'  => $this->clock->format(),
        ];
        if (!empty($prefs['locale'])) {
            $update['locale'] = mb_substr((string) $prefs['locale'], 0, 8);
        }
        $this->db->table('users')->where('id', '=', $userId)->update($update);
    }

    /** Link (or create) a resident login for a resident record. */
    public function ensureResidentAccount(int $residentId, ?string $login = null, ?string $password = null, ?User $actor = null): array
    {
        $existing = $this->db->table('users')->where('resident_id', '=', $residentId)->whereNull('deleted_at')->first();
        if ($existing !== null) {
            return ['ok' => true, 'id' => (int) $existing['id'], 'login' => (string) $existing['login'], 'existed' => true];
        }

        $resident = $this->db->table('residents')->where('id', '=', $residentId)->first();
        if ($resident === null) {
            return ['ok' => false, 'error' => 'Житель не найден.'];
        }

        $login ??= $this->suggestLogin((string) ($resident['phone'] ?? ''), (string) ($resident['email'] ?? ''), (string) ($resident['apartment'] ?? ''), (string) ($resident['full_name'] ?? ''));
        $result = $this->auth->createAccount([
            'login' => $login,
            'email' => $resident['email'] ?? null,
            'phone' => $resident['phone'] ?? null,
            'full_name' => $resident['full_name'] ?? $login,
            'role' => 'resident',
            'resident_id' => $residentId,
            'password' => $password ?? $this->auth->generatePassword(12),
            'is_active' => true,
            'must_change_password' => $password === null,
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Не удалось создать учётную запись.'];
        }

        $this->audit->info('user.create_resident', $actor, 'user', (string) $result['id'], $login, null, ['resident_id' => $residentId]);

        return ['ok' => true, 'id' => (int) $result['id'], 'login' => $login, 'password' => (string) ($result['password'] ?? ''), 'existed' => false];
    }

    private function suggestLogin(string $phone, string $email, string $apartment, string $fullName): string
    {
        // Phone first: residents sign in with the number they already know.
        $digits = ltrim(\BasIp\Support\Phone::normalize($phone), '+');
        if (strlen($digits) >= 10 && $this->guard->findByLogin($digits) === null
            && $this->db->table('users')->where('login', '=', $digits)->whereNull('deleted_at')->count() === 0) {
            return $digits;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        $base = Str::slug(Str::transliterate($fullName !== '' ? $fullName : ('kvartira ' . $apartment)), '.');
        if ($base === '') {
            $base = 'resident';
        }
        $login = mb_substr($base, 0, 40);
        $i = 1;
        while ($this->guard->findByLogin($login) !== null) {
            $login = mb_substr($base, 0, 36) . $i++;
        }

        return $login;
    }

    /**
     * Active sessions of a user, most recently used first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeSessions(int $userId): array
    {
        return $this->db->table('sessions_meta')
            ->where('user_id', '=', $userId)
            ->orderBy('last_used_at', 'desc')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentLogins(int $limit = 20): array
    {
        return $this->db->table('login_attempts a')
            ->select('a.*', 'u.login AS user_login')
            ->leftJoin('users u', 'u.id', '=', 'a.user_id')
            ->orderBy('a.id', 'desc')
            ->limit($limit)
            ->get();
    }
}
