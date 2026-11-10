<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Geo;

/**
 * Bounded straight-line pickup ETA, for ranking only: distance x detour
 * factor / average city speed, capped. Labelled "straight_line_estimate"
 * everywhere it appears. Never used for fares (D-4).
 */
class StraightLineEta implements PickupEtaEstimator
{
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): EtaEstimate
    {
        $straight = Geo::distanceM($fromLat, $fromLng, $toLat, $toLng);
        $road = (int) round($straight * (float) config('ehail_dispatch.eta.detour_factor'));
        $speedMs = max(1.0, (float) config('ehail_dispatch.eta.speed_kmh') / 3.6);
        $seconds = (int) min((int) config('ehail_dispatch.eta.max_seconds'), (int) ceil($road / $speedMs));

        return new EtaEstimate($seconds, $road, EtaEstimate::STRAIGHT_LINE);
    }
}
