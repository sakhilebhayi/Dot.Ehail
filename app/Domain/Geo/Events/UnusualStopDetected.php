<?php

namespace App\Domain\Geo\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trip in progress has been stationary for longer than ehail-geo.deviation.stop_seconds away from the drop-off.
 * Consumed by the safety domain. Raised at most once per ride per cooldown.
 *
 * context: stop_radius_m, samples, distance_to_dropoff_m
 */
class UnusualStopDetected
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $rideId,
        public int $driverId,
        public int $stoppedSeconds,
        public float $lat,
        public float $lng,
        public CarbonImmutable $occurredAt,
        public array $context = [],
    ) {}
}
