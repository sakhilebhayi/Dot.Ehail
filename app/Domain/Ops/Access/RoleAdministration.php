<?php

namespace App\Domain\Ops\Access;

use App\Domain\Ops\Audit\OpsAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Granting and removing operations access. Only an administrator calls this (route permission roles.manage);
 * every change is one audit row with the before and after role sets. Removing the last role is refused (that would
 * turn the person into a legacy full-access operator, D-1001): remove operator access instead.
 */
class RoleAdministration
{
    public function __construct(private readonly OpsAccess $access, private readonly OpsAudit $audit) {}

    /**
     * Make a person an operator with these roles (or replace an operator's roles).
     *
     * @param  list<string>  $roles
     */
    public function setRoles(User $actor, User $subject, array $roles): void
    {
        $roles = array_values(array_unique(array_filter($roles, fn ($role) => OpsRole::tryFrom((string) $role) !== null)));
        sort($roles);

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'Choose at least one role. To take away all access, remove operator access instead.']);
        }

        $this->guardLastAdministrator($actor, $subject, $roles);

        $before = $subject->isPlatformOperator() ? $this->access->roles($subject) : null;

        DB::transaction(function () use ($actor, $subject, $roles): void {
            if (! $subject->isPlatformOperator()) {
                $subject->forceFill(['is_platform_operator' => true])->save();
            }

            OpsRoleAssignment::query()->where('user_id', $subject->id)->whereNotIn('role', $roles)->delete();

            foreach ($roles as $role) {
                OpsRoleAssignment::query()->firstOrCreate(['user_id' => $subject->id, 'role' => $role], ['granted_by' => $actor->id]);
            }
        });

        $this->access->forget($subject);

        $this->audit->action('ops.roles_changed', $actor, $subject, [
            'before' => $before === null ? 'not_an_operator' : ($before === [] ? 'legacy_full_access' : $before),
            'after' => $roles,
        ]);
    }

    public function removeOperator(User $actor, User $subject): void
    {
        if ($actor->is($subject)) {
            throw ValidationException::withMessages(['user' => 'You cannot remove your own operator access.']);
        }

        $this->guardLastAdministrator($actor, $subject, []);

        $before = $this->access->roles($subject);

        DB::transaction(function () use ($subject): void {
            OpsRoleAssignment::query()->where('user_id', $subject->id)->delete();
            $subject->forceFill(['is_platform_operator' => false])->save();
        });

        $this->access->forget($subject);

        $this->audit->action('ops.operator_removed', $actor, $subject, ['before' => $before === [] ? 'legacy_full_access' : $before]);
    }

    /** @param list<string> $newRoles */
    private function guardLastAdministrator(User $actor, User $subject, array $newRoles): void
    {
        if (! $this->access->isAdmin($subject) || in_array(OpsRole::Admin->value, $newRoles, true)) {
            return;
        }

        $otherAdmins = User::query()
            ->where('is_platform_operator', true)
            ->whereKeyNot($subject->id)
            ->get()
            ->filter(fn (User $user) => $this->access->isAdmin($user))
            ->count();

        if ($otherAdmins === 0) {
            throw ValidationException::withMessages(['roles' => 'This is the last person who can manage roles. Make someone else an administrator first.']);
        }
    }
}
