<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEarningsEstimator;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Models\Ride;
use App\Models\User;

/**
 * The offer card's "you would earn": pricing's EarningsSplitter on the ride's quoted fare snapshot under the
 * commission plan that would apply to this driver (fleet, area, category). Null when there is no snapshot or
 * no commission plan: the driver app then shows nothing rather than a guess (D-308).
 */
class PricingEarningsEstimator implements DriverEarningsEstimator
{
    public function __construct(private readonly EarningsSplitter $splitter) {}

    public function estimateMinor(Ride $ride, User $driver): ?int
    {
        return $this->splitter->estimateForRide($ride, $driver->id)?->driverNetMinor;
    }
}
