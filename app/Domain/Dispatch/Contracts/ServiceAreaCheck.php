<?php

namespace App\Domain\Dispatch\Contracts;

/**
 * Seam to the geo domain's ZoneResolver::serviceAreaFor. true/false when an
 * answer exists; null when no service areas are configured (the dispatcher
 * then does not filter by area, and says so in the offer's score terms).
 */
interface ServiceAreaCheck
{
    public function covers(float $lat, float $lng): ?bool;
}
