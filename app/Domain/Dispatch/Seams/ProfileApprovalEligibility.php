<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\DriverEligibility;
use App\Domain\Dispatch\Data\EligibilityResult;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Default DriverEligibility until the safety domain's VerificationService is
 * bound: the rules that already exist (an approved driver profile, the
 * driver's own active vehicle). It does not check document expiry because no
 * such data exists yet; the safety binding adds that.
 */
class ProfileApprovalEligibility implements DriverEligibility
{
    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();
        $reasons = [];

        if ($profile === null) {
            $reasons['no_driver_profile'] = 'You have not applied to drive yet.';
        } elseif ($profile->status !== 'approved') {
            $reasons['profile_not_approved'] = 'Your driver profile is not approved.';
        }

        if ($profile === null || $vehicle->driver_profile_id !== $profile->id) {
            $reasons['vehicle_not_yours'] = 'That vehicle is not registered to you.';
        } elseif (! $vehicle->is_active) {
            $reasons['vehicle_inactive'] = 'That vehicle is not your active vehicle.';
        }

        return $reasons === [] ? EligibilityResult::allow() : EligibilityResult::deny($reasons);
    }
}
