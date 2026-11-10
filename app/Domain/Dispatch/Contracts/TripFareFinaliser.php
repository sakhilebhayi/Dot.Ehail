<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\FinalFare;
use App\Models\Ride;

/**
 * Seam to the pricing domain's FareFinaliser. The default binding
 * (Seams\AgreedFareFinaliser) completes a quoted ride at the agreed (quoted)
 * fare, and a pre-R-46 ride at its estimate, exactly as before R-46.
 * Returns null when no fare can be produced (an operator must enter it).
 */
interface TripFareFinaliser
{
    public function finalise(Ride $ride): ?FinalFare;
}
