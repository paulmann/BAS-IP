<?php

declare(strict_types=1);

namespace BasIp\Auth;

/**
 * Machine principal authenticated with an API token.
 *
 * Deliberately *not* a {@see User}: tokens grant scopes, not roles, which keeps
 * a leaked automation token from becoming an admin session.
 */
final class ApiPrincipal
{
    /** @param array<int, string> $scopes */
    public function __construct(
        public readonly int $tokenId,
        public readonly string $name,
        public readonly array $scopes,
        public readonly ?User $user = null,
    ) {
    }

    public function can(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    public function role(): string
    {
        return $this->user?->role ?? 'api';
    }

    public function displayName(): string
    {
        return 'API: ' . $this->name;
    }
}
