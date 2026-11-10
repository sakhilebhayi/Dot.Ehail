<?php

namespace App\Domain\Geo\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trip in progress has been off its planned road route for several consecutive good GPS samples. Consumed by
 * the safety domain (check-in prompt to the passenger, operations alert). Raised at most once per ride per
 * ehail-geo.deviation.cooldown_seconds.
 *
 * context: threshold_m, samples, planned_provider, max_off_route_m
 */
class RouteDeviationDetected
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $rideId,
        public int $driverId,
        public int $offRouteMetres,
        public float $lat,
        public float $lng,
        public CarbonImmutable $occurredAt,
        public array $context = [],
    ) {}
}
