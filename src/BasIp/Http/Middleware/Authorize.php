<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Auth\ApiPrincipal;
use BasIp\Auth\Guard;
use BasIp\Auth\User;
use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Authorisation.
 *
 * Route options:
 *   'roles'       => ['admin','operator']   — restrict by role
 *   'permission'  => 'residents.edit'       — require a single permission
 *   'permissions' => ['a','b']              — require ALL (use 'any' for OR)
 *   'any'         => ['a','b']              — require ANY
 *   'scopes'      => ['gate.open']          — API token scopes
 *
 * Residents are additionally confined to their own records: controllers must
 * call `Guard::requirePermission()` / compare `resident_id` for row-level
 * access, and every admin route is unreachable for role=resident by design.
 */
final class Authorize extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $this->container->has('route') ? (array) $this->container->get('route') : [];
        $options = (array) ($route['options'] ?? []);

        $roles      = isset($options['roles']) ? (array) $options['roles'] : [];
        $permission = $options['permission'] ?? null;
        $permissions = isset($options['permissions']) ? (array) $options['permissions'] : [];
        $any        = isset($options['any']) ? (array) $options['any'] : [];
        $scopes     = isset($options['scopes']) ? (array) $options['scopes'] : [];

        if ($roles === [] && $permission === null && $permissions === [] && $any === [] && $scopes === []) {
            return $next($request);
        }

        $principal = $this->container->has('auth.principal') ? $this->container->get('auth.principal') : null;

        // API token principal → scope check.
        if ($principal instanceof ApiPrincipal) {
            if ($scopes !== []) {
                foreach ($scopes as $scope) {
                    if (!$principal->can((string) $scope)) {
                        return $this->forbidden($request, 'Токену не хватает области доступа: ' . $scope);
                    }
                }

                return $next($request);
            }
            if ($roles !== [] && !in_array($principal->role(), $roles, true)) {
                return $this->forbidden($request, 'Недостаточно прав для этого раздела.');
            }

            return $next($request);
        }

        /** @var Guard $guard */
        $guard = $this->container->get(Guard::class);
        $user  = $guard->user();

        if (!$user instanceof User) {
            return $this->forbidden($request, 'Требуется авторизация.', 401);
        }

        if ($roles !== [] && !in_array($user->role, $roles, true)) {
            return $this->forbidden($request, 'Раздел доступен только для ролей: ' . implode(', ', $roles));
        }

        if (is_string($permission) && $permission !== '' && !$user->can($permission)) {
            return $this->forbidden($request, 'Недостаточно прав: ' . $permission);
        }

        foreach ($permissions as $required) {
            if (!$user->can((string) $required)) {
                return $this->forbidden($request, 'Недостаточно прав: ' . $required);
            }
        }

        if ($any !== []) {
            $ok = false;
            foreach ($any as $candidate) {
                if ($user->can((string) $candidate)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return $this->forbidden($request, 'Недостаточно прав для этого действия.');
            }
        }

        return $next($request);
    }

    private function forbidden(Request $request, string $message, int $status = 403): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'error' => $message, 'status' => $status], $status);
        }
        $this->session()?->flash('error', $message);

        return Response::redirect($request->url($status === 401 ? '/login' : '/'));
    }
}
