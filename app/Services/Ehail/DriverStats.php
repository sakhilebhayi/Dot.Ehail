<?php

namespace App\Services\Ehail;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRating;

/**
 * driver_profiles.rating and total_rides are derived figures. They are
 * recomputed from the rows that define them -- never incremented -- so calling
 * refresh() any number of times, or after any drift, lands on the same values.
 *
 *   rating      = mean of passengers' ratings of this person AS A DRIVER
 *   total_rides = completed rides this person drove
 */
class DriverStats
{
    public function refresh(int $driverUserId): void
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driverUserId)->first();

        if ($profile === null) {
            return;
        }

        $average = RideRating::where('rated_user_id', $driverUserId)
            ->where('rated_role', RideRating::ROLE_DRIVER)
            ->avg('rating');

        $profile->forceFill([
            'rating' => $average === null ? 0 : round((float) $average, 2),
            'total_rides' => Ride::where('driver_id', $driverUserId)->where('status', 'completed')->count(),
        ])->save();
    }
}
