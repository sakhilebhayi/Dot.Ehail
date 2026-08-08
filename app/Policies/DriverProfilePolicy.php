<?php

namespace App\Policies;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Security fix (platform-loop pass, 2026-08-01): before this policy, nothing
 * enforced that a driver's profile page — which surfaces their full ride
 * history, vehicle, license number, and ID number — could only be viewed by
 * that driver. There was no authorization check at all on a direct-access
 * route (`/drivers/{driverProfile}`), so any authenticated user could have
 * viewed any driver's ride history and personal identifiers by ID. This
 * policy closes that gap and is enforced by DriverController@show.
 */
class DriverProfilePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any driver profiles (scoping happens per-record in view()).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the given driver profile and its ride history.
     *
     * Extended (2026-08-09, Fleet/Operator entity): also allows the
     * driver's own fleet owner -- they now have a real reason to
     * (reviewing/having reviewed the application). This is additive to the
     * 2026-08-01 fix's intent: a stranger still cannot view an unrelated
     * driver's profile.
     */
    public function view(User $user, DriverProfile $driverProfile): bool
    {
        if ($user->id === $driverProfile->user_id) {
            return true;
        }

        return $driverProfile->fleet && $user->id === $driverProfile->fleet->owner_user_id;
    }
}
