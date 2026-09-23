<?php

namespace App\Policies;

use App\Models\DriverProfile;
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

    /**
     * Any authenticated user may request a ride as a passenger -- being a
     * driver elsewhere in the platform doesn't disqualify someone from
     * also riding, matching how real ride-hailing apps work.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * A driver may claim an open request if they have an approved,
     * online DriverProfile with at least one active vehicle -- matches
     * the same "can this user actually drive" check DriverController's
     * own views already rely on via DriverProfile::activeVehicle().
     */
    public function accept(User $user, Ride $ride): bool
    {
        if ($ride->status !== 'requested' || $ride->driver_id !== null) {
            return false;
        }

        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->first();

        return $profile !== null
            && $profile->status === 'approved'
            && $profile->is_online
            && $profile->activeVehicle() !== null;
    }

    /**
     * Only the ride's assigned driver may progress it through its
     * lifecycle (en_route -> arrived -> in_progress -> completed).
     */
    public function advance(User $user, Ride $ride): bool
    {
        return $ride->driver_id !== null && $user->id === $ride->driver_id;
    }

    /**
     * Either party may cancel, but only before the trip is actually
     * underway -- once in_progress, cancelling would leave a passenger
     * mid-trip with no driver, so completion is the only path forward
     * from there.
     */
    public function cancel(User $user, Ride $ride): bool
    {
        return in_array($ride->status, ['requested', 'accepted', 'en_route', 'arrived'], true)
            && ($user->id === $ride->passenger_id || $user->id === $ride->driver_id);
    }
}
