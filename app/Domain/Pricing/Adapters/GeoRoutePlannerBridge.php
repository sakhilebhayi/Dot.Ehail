<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Exceptions\RouteUnavailable;

/**
 * Adapts the geo domain's App\Domain\Geo\Routing\RoutePlanner. Bound by EhailPricingServiceProvider only when that
 * class exists, so this branch runs with or without geo merged. Geo classes are referenced by string on purpose.
 */
final class GeoRoutePlannerBridge implements PricingRoutePlanner
{
    public const PLANNER = 'App\\Domain\\Geo\\Routing\\RoutePlanner';

    public const LAT_LNG = 'App\\Domain\\Geo\\LatLng';

    public const UNAVAILABLE = 'App\\Domain\\Geo\\Exceptions\\RoutingUnavailable';

    public function __construct(private object $planner) {}

    public static function available(): bool
    {
        return class_exists(self::PLANNER) && class_exists(self::LAT_LNG);
    }

    public function isConfigured(): bool
    {
        return (bool) $this->planner->isConfigured();
    }

    public function route(GeoPoint $from, GeoPoint $to): PricedRoute
    {
        $latLng = self::LAT_LNG;

        try {
            $result = $this->planner->route(new $latLng($from->lat, $from->lng), new $latLng($to->lat, $to->lng));
        } catch (\Throwable $e) {
            if (is_a($e, self::UNAVAILABLE)) {
                throw new RouteUnavailable((string) $e->reason, $e->getMessage());
            }

            throw $e;
        }

        if (($result->kind ?? 'road') !== 'road') {
            throw new RouteUnavailable('provider_error', 'The routing service returned something other than a road route.');
        }

        return new PricedRoute((int) $result->distanceM, (int) $result->durationS, (string) $result->polyline, (string) $result->provider);
    }
}
