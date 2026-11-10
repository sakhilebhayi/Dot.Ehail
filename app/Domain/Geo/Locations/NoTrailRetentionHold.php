<?php

namespace App\Domain\Geo\Locations;

class NoTrailRetentionHold implements TrailRetentionHold
{
    public function held(array $rideIds): array
    {
        return [];
    }
}
