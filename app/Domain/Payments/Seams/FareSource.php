<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;

/**
 * Where the money domain reads a completed trip's final fare. The contract's pricing seam is
 * FareFinaliser::finalise(Ride, TripActuals): FinalFare; at merge an adapter binding this interface
 * to it replaces LegacyFareSource. Returns null when the ride has no final fare.
 */
interface FareSource
{
    public function finalFareFor(Ride $ride): ?FareBreakdown;
}
