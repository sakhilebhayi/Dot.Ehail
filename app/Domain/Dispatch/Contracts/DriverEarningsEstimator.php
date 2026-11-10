<?php

namespace App\Domain\Dispatch\Contracts;

use App\Models\Ride;
use App\Models\User;

/**
 * Seam to the pricing domain's EarningsSplitter, used to show a driver what a
 * trip would pay before they accept. Null when no estimate exists; the feed
 * then sends null and the driver app shows nothing rather than a guess.
 */
interface DriverEarningsEstimator
{
    public function estimateMinor(Ride $ride, User $driver): ?int;
}
