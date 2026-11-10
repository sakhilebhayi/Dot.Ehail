<?php

namespace App\Domain\Geo\Locations;

/**
 * Seam to the safety domain: a ride under an open incident or dispute keeps its raw trail past the normal
 * retention period. The default holds nothing; safety rebinds this when it lands.
 */
interface TrailRetentionHold
{
    /**
     * @param  list<int>  $rideIds
     * @return list<int> the subset that must be kept
     */
    public function held(array $rideIds): array;
}
