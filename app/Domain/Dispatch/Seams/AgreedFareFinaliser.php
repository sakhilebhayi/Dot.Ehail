<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\TripFareFinaliser;
use App\Domain\Dispatch\Data\FinalFare;
use App\Models\Ride;

/**
 * Default TripFareFinaliser: the agreed (quoted) fare for a quoted ride; the
 * pre-R-46 estimate for a ride made by the old form (what completion did
 * before R-46). Pricing's FareFinaliser replaces it with the tolerance rules
 * of architecture section 4.
 */
class AgreedFareFinaliser implements TripFareFinaliser
{
    public function finalise(Ride $ride): ?FinalFare
    {
        if ($ride->quoted_total_minor !== null) {
            return new FinalFare($ride->quoted_total_minor, $ride->currency ?? (string) config('ehail.currency.code'), 'agreed_fare');
        }

        if ($ride->estimated_fare !== null) {
            return new FinalFare((int) round(((float) $ride->estimated_fare) * 100), (string) config('ehail.currency.code'), 'legacy_estimate');
        }

        return null;
    }
}
