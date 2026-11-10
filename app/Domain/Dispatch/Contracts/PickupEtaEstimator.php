<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\EtaEstimate;

/**
 * Time for a driver to reach a pickup point. Used to RANK drivers and to show
 * an ETA; never to bill. The geo domain's RoutePlanner backs it when merged
 * (method "road"); the default is a bounded straight-line estimate
 * (method "straight_line_estimate"), which the feed labels as such.
 */
interface PickupEtaEstimator
{
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): EtaEstimate;
}
