<?php

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Exceptions\RouteUnavailable;

/**
 * The geo routing seam (architecture section 3, RoutePlanner) as pricing consumes it. Bound to
 * GeoRoutePlannerBridge when the geo domain is present, otherwise to UnconfiguredRoutePlanner. There is no
 * straight-line implementation and there must never be one: fares are priced on road routes only (D-4).
 */
interface PricingRoutePlanner
{
    public function isConfigured(): bool;

    /**
     * @throws RouteUnavailable
     */
    public function route(GeoPoint $from, GeoPoint $to): PricedRoute;
}
