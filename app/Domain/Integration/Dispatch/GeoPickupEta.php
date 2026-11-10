<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Seams\StraightLineEta;
use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RoutePlanner;

/**
 * Pickup ETA from geo's road router (cached, circuit-broken), labelled "road"; when no routing provider is
 * configured or it fails, the bounded straight-line estimate labelled "straight_line_estimate". Ranking and
 * display only, never billing (D-4). The dispatcher's scorer limits router calls to the closest candidates
 * within its time budget (ehail_dispatch.eta.router_budget_ms).
 */
class GeoPickupEta implements PickupEtaEstimator
{
    public function __construct(private readonly RoutePlanner $routes, private readonly StraightLineEta $fallback) {}

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): EtaEstimate
    {
        if (! $this->routes->isConfigured()) {
            return $this->fallback->estimate($fromLat, $fromLng, $toLat, $toLng);
        }

        try {
            $route = $this->routes->route(new LatLng($fromLat, $fromLng), new LatLng($toLat, $toLng));
        } catch (RoutingUnavailable) {
            return $this->fallback->estimate($fromLat, $fromLng, $toLat, $toLng);
        }

        return new EtaEstimate($route->durationS, $route->distanceM, EtaEstimate::ROAD);
    }
}
