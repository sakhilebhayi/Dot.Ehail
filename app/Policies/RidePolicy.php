<?php

namespace App\Policies;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * `Ride` has no fleet/team relation yet (see wiki.md §3/§7 — fleet-as-entity
 * isn't modeled), so the existing `/dashboard` route already surfaces every
 * ride platform-wide to any authenticated user by design — that is today's
 * documented operator ops view, not a gap this pass introduces or changes.
 * This policy exists so the new `/rides/{ride}` detail route (added for the
 * ride search flow) is authorized the same way instead of being wide open
 * with no Gate check at all.
 */
class RidePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ride $ride): bool
    {
        return true;
    }
}
