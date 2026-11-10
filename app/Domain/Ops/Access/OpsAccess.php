<?php

namespace App\Domain\Ops\Access;

use App\Models\User;

/**
 * Who may do what in operations. Roles sit on top of users.is_platform_operator: a person who is not a platform
 * operator holds no operations permission at all, whatever rows exist; a platform operator with no role rows keeps
 * the full access every operator had before roles existed (legacy full access, D-1001) until an administrator
 * assigns roles. Answers are memoised per user for the request.
 */
class OpsAccess
{
    /** @var array<int, list<string>> */
    private array $roleCache = [];

    public function isOperator(?User $user): bool
    {
        return $user !== null && $user->isPlatformOperator();
    }

    /** @return list<string> role values held by the user (empty for a legacy operator) */
    public function roles(User $user): array
    {
        return $this->roleCache[$user->id] ??= OpsRoleAssignment::query()
            ->where('user_id', $user->id)
            ->orderBy('role')
            ->pluck('role')
            ->filter(fn (string $role) => OpsRole::tryFrom($role) !== null)
            ->values()
            ->all();
    }

    public function hasLegacyFullAccess(User $user): bool
    {
        return $this->isOperator($user) && $this->roles($user) === [];
    }

    public function isAdmin(?User $user): bool
    {
        if (! $this->isOperator($user)) {
            return false;
        }

        return $this->hasLegacyFullAccess($user) || in_array(OpsRole::Admin->value, $this->roles($user), true);
    }

    /** @return list<string> */
    public function permissions(?User $user): array
    {
        if (! $this->isOperator($user)) {
            return [];
        }

        if ($this->isAdmin($user)) {
            return OpsPermission::all();
        }

        $permissions = [];
        foreach ($this->roles($user) as $role) {
            array_push($permissions, ...OpsRole::from($role)->permissions());
        }

        return array_values(array_unique($permissions));
    }

    public function can(?User $user, string $permission): bool
    {
        if (! $this->isOperator($user)) {
            return false;
        }

        if ($permission === OpsPermission::UNMAPPED) {
            return $this->isAdmin($user);
        }

        return in_array($permission, $this->permissions($user), true);
    }

    /** Any of the given permissions. */
    public function canAny(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the user may use a route behind the `operator` middleware (RoutePermissions decides the permission). */
    public function allowsRoute(?User $user, ?string $routeName): bool
    {
        return $this->canAny($user, RoutePermissions::for($routeName));
    }

    public function forget(User $user): void
    {
        unset($this->roleCache[$user->id]);
    }
}
