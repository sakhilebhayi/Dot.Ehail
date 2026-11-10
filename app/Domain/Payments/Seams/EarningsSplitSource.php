<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;
use App\Models\User;

/**
 * The contract's EarningsSplitter::split(FinalFare, DriverArrangement): Split. Bound to
 * ConfigCommissionSplitter until the pricing domain's commission plans are wired at merge.
 */
interface EarningsSplitSource
{
    public function split(Ride $ride, FareBreakdown $fare, User $driver): EarningsSplit;
}
