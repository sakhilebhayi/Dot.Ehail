<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Exceptions\RouteUnavailable;

/**
 * Bound when the geo domain is absent: every route request is honestly unavailable.
 */
final class UnconfiguredRoutePlanner implements PricingRoutePlanner
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function route(GeoPoint $from, GeoPoint $to): PricedRoute
    {
        throw new RouteUnavailable('not_configured');
    }
}
