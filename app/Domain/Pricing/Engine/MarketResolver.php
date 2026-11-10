<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\Market;

/**
 * The market for a pickup point. With the geo zone seam wired, a point outside every service area has NO market
 * (null) and cannot be priced. Without it (geo not merged yet) the configured country default applies; this is
 * logged as D-306 and goes away once GeoZoneLookupBridge is bound.
 */
final class MarketResolver
{
    public function __construct(private PricingZoneLookup $zones) {}

    public function forPoint(GeoPoint $point): ?Market
    {
        if (! $this->zones->isConfigured()) {
            return Market::defaults();
        }

        $area = $this->zones->serviceAreaFor($point);

        return $area === null ? null : new Market($area->country, $area->currency, $area->timezone, $area);
    }

    public function forServiceArea(?int $serviceAreaId): Market
    {
        if ($serviceAreaId === null || ! $this->zones->isConfigured()) {
            return Market::defaults();
        }

        $area = $this->zones->serviceArea($serviceAreaId);

        return $area === null ? Market::defaults() : new Market($area->country, $area->currency, $area->timezone, $area);
    }
}
