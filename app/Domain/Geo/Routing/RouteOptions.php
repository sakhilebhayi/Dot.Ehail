<?php

namespace App\Domain\Geo\Routing;

/**
 * Only the car profile exists today. avoidTolls is passed to providers that support it (Google, Mapbox, ORS,
 * Valhalla); OSRM ignores it.
 */
final readonly class RouteOptions
{
    public function __construct(
        public string $profile = 'driving',
        public bool $avoidTolls = false,
        public bool $useCache = true,
    ) {}
}
