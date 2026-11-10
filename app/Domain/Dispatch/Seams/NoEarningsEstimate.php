<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\DriverEarningsEstimator;
use App\Models\Ride;
use App\Models\User;

/**
 * Default DriverEarningsEstimator: no commission plans exist until pricing is
 * bound, so there is no estimate (null), never a guess.
 */
class NoEarningsEstimate implements DriverEarningsEstimator
{
    public function estimateMinor(Ride $ride, User $driver): ?int
    {
        return null;
    }
}
