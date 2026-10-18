<?php

namespace App\Policies;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;

class FleetPolicy
{
    /**
     * The fleet's owner and platform operators may view it (its
     * pending/reviewed driver applications).
     */
    public function view(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id || $user->isPlatformOperator();
    }

    /**
     * Approve or reject a driver application. The approval gate:
     *
     *  - nobody ever reviews their own application (this closes the hole
     *    where an applicant created a fleet in the same form, became its
     *    owner, and approved themselves -- operators included, so a
     *    self-application needs a second operator);
     *  - a platform operator may review any other applicant;
     *  - a fleet owner may review other applicants, but only once an
     *    operator has approved the fleet itself.
     */
    public function reviewDriver(User $user, Fleet $fleet, DriverProfile $driverProfile): bool
    {
        if ($user->id === $driverProfile->user_id) {
            return false;
        }

        if ($user->isPlatformOperator()) {
            return true;
        }

        return $fleet->isApproved() && $user->id === $fleet->owner_user_id;
    }

    /**
     * The register of every vehicle driven in a fleet: platform operators, and
     * the owner once an operator has approved the fleet (an unvetted fleet's
     * owner is only an applicant until then).
     */
    public function viewVehicles(User $user, Fleet $fleet): bool
    {
        return $user->isPlatformOperator()
            || ($fleet->isApproved() && $user->id === $fleet->owner_user_id);
    }
}
