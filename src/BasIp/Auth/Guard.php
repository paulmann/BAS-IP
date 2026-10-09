<?php

declare(strict_types=1);

namespace BasIp\Auth;

use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Core\Session;
use BasIp\Db\Database;
use BasIp\Http\RoutingException;

/**
 * Authentication guard.
 *
 * Resolves the current principal from (in order):
 *   1. the session (`user_id`)
 *   2. a `remember_me` cookie
 *   3. a bearer token / `X-API-Key` header (machine access, no session)
 *
 * and exposes role checks used by the `Authorize` middleware.
 */
final class Guard
{
    private ?User $user = null;
    private bool $resolved = false;
    private ?string $via = null;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Session $session,
        private readonly Crypto $crypto,
    ) {
    }

    public function user(): ?User
    {
        $this->resolve();

        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function via(): ?string
    {
        $this->resolve();

        return $this->via;
    }

    public function isAdmin(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function isStaff(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function isResident(): bool
    {
        return $this->user()?->isResident() ?? false;
    }

    public function can(string $permission): bool
    {
        return $this->user()?->can($permission) ?? false;
    }

    /** Fail hard — used by controllers that must never run anonymously. */
    public function requireUser(): User
    {
        $user = $this->user();
        if ($user === null) {
            throw RoutingException::unauthorized();
        }

        return $user;
    }

    public function requirePermission(string $permission): User
    {
        $user = $this->requireUser();
        if (!$user->can($permission)) {
            throw RoutingException::forbidden('Недостаточно прав: ' . $permission);
        }

        return $user;
    }

    /** @param array<int, string> $roles */
    public function requireRole(array $roles): User
    {
        $user = $this->requireUser();
        if (!in_array($user->role, $roles, true)) {
            throw RoutingException::forbidden('Раздел доступен только для роли: ' . implode(', ', $roles));
        }

        return $user;
    }

    public function login(User $user, bool $remember = false, ?Request $request = null): void
    {
        if ($this->config->bool('auth.session.regenerate_on_login', true)) {
            $this->session->regenerate();
        }
        $this->session->set('user_id', $user->id);
        $this->session->set('user_role', $user->role);
        $this->session->set('login_at', time());
        $this->session->forget('pending_2fa_user_id');
        $this->session->forget('login_attempts');
        $this->session->touch();

        $this->user = $user;
        $this->resolved = true;
        $this->via = 'session';

        if ($remember && $this->config->bool('auth.session.remember_me.enabled', true)) {
            $this->issueRememberCookie($user, $request);
        }

        $this->recordSession($user, $request);
    }

    public function logout(?Request $request = null): void
    {
        $userId = $this->id();
        if ($userId !== null) {
            $this->db->table('sessions_meta')->where('session_id', '=', hash('sha256', $this->session->id()))->delete();
        }
        $this->session->destroy();
        $this->user = null;
        $this->resolved = false;
        $this->via = null;
        if ($request !== null) {
            $this->clearRememberCookie($request);
        }
    }

    /** Force-logout every other session of a user (after a password change). */
    public function logoutOtherSessions(int $userId, ?string $currentSessionHash = null): int
    {
        $query = $this->db->table('sessions_meta')->where('user_id', '=', $userId);
        if ($currentSessionHash !== null) {
            $query = $query->where('session_id', '!=', $currentSessionHash);
        }

        return $query->delete();
    }

    public function currentSessionHash(): string
    {
        return hash('sha256', $this->session->id());
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $id = $this->session->get('user_id');
        if (is_int($id) && $id > 0) {
            $user = $this->findById($id);
            if ($user !== null && $user->isActive && !$user->isLocked()) {
                $this->user = $user;
                $this->via  = 'session';

                return;
            }
            // Stale session → drop it.
            $this->session->forget('user_id');
        }
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->table('users')->where('id', '=', $id)->whereNull('deleted_at')->first();

        return $row === null ? null : User::fromRow($row);
    }

    public function findByLogin(string $login): ?User
    {
        $login = trim($login);
        $row = $this->db->table('users')
            ->whereNull('deleted_at')
            ->where(static function ($q) use ($login): void {
                $q->where('login', '=', $login)->orWhere('email', '=', $login);
            })
            ->first();

        return $row === null ? null : User::fromRow($row);
    }

    public function findByResidentId(int $residentId): ?User
    {
        $row = $this->db->table('users')->where('resident_id', '=', $residentId)->whereNull('deleted_at')->first();

        return $row === null ? null : User::fromRow($row);
    }

    /** Used by the API middleware: bearer token → synthetic principal. */
    public function authenticateApiToken(string $token): ?ApiPrincipal
    {
        $hash = Crypto::hashToken($token);
        $row = $this->db->table('api_tokens')
            ->where('token_hash', '=', $hash)
            ->where('is_active', '=', 1)
            ->first();

        if ($row === null) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $this->db->table('api_tokens')->where('id', '=', (int) $row['id'])->update([
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        $scopes = [];
        if (!empty($row['scopes'])) {
            $decoded = json_decode((string) $row['scopes'], true);
            if (is_array($decoded)) {
                $scopes = $decoded;
            }
        }

        $user = null;
        if (!empty($row['user_id'])) {
            $user = $this->findById((int) $row['user_id']);
        }

        return new ApiPrincipal(
            tokenId: (int) $row['id'],
            name: (string) $row['name'],
            scopes: $scopes,
            user: $user,
        );
    }

    private function issueRememberCookie(User $user, ?Request $request): void
    {
        $plain = bin2hex(random_bytes(32));
        $hash  = hash_hmac('sha256', $plain, (string) $this->config->get('app.key', 'bas-ip'));
        $this->db->table('users')->where('id', '=', $user->id)->update(['remember_token' => $hash]);

        $days = $this->config->int('auth.session.remember_me.days', 30);
        $this->session->set('remember_cookie', [
            'name'   => $this->rememberCookieName($request),
            'value'  => $user->id . ':' . $plain,
            'expires' => time() + $days * 86400,
        ]);
    }

    private function clearRememberCookie(?Request $request): void
    {
        $this->session->set('remember_cookie', [
            'name' => $this->rememberCookieName($request), 'value' => '', 'expires' => time() - 3600,
        ]);
    }

    private function rememberCookieName(?Request $request): string
    {
        $name = 'basip_remember';
        if ($request !== null && $request->isSecure() && $this->config->bool('security.cookies.prefix_secure')) {
            $name = '__Secure-' . $name;
        }

        return $name;
    }

    private function recordSession(User $user, ?Request $request): void
    {
        $now = date('Y-m-d H:i:s');
        $lifetime = $this->config->int('auth.session.lifetime', 720) * 60;
        try {
            $this->db->table('sessions_meta')->upsert([
                'session_id'   => $this->currentSessionHash(),
                'user_id'      => $user->id,
                'ip'           => $request?->ip($this->config),
                'user_agent'   => $request !== null ? $request->userAgent() : 'cli',
                'created_at'   => $now,
                'last_used_at' => $now,
                'expires_at'   => date('Y-m-d H:i:s', time() + $lifetime),
            ], ['session_id']);
        } catch (\Throwable) {
            // Session bookkeeping must never break a login.
        }
    }

    public function decryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->crypto->isEncrypted($value) ? $this->crypto->decrypt($value, 'totp') : $value;
    }
}
