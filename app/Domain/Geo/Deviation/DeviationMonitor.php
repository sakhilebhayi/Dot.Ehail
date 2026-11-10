<?php

namespace App\Domain\Geo\Deviation;

use App\Domain\Geo\Events\RouteDeviationDetected;
use App\Domain\Geo\Events\UnusualStopDetected;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the detector after new trip points arrive and raises at most one event per ride per kind per cooldown.
 */
class DeviationMonitor
{
    public function __construct(private DeviationDetector $detector) {}

    public function evaluate(Ride $ride): ?Deviation
    {
        $deviation = $this->detector->check($ride);

        if ($deviation === null) {
            return null;
        }

        $cooldown = (int) config('ehail-geo.deviation.cooldown_seconds', 600);

        if (! Cache::add('ehail-geo:deviation:'.$ride->id.':'.$deviation->kind, true, $cooldown)) {
            return null;
        }

        if ($deviation->kind === 'route_deviation') {
            RouteDeviationDetected::dispatch(
                $deviation->rideId, $deviation->driverId, (int) $deviation->metres,
                $deviation->position->lat, $deviation->position->lng, $deviation->detectedAt, $deviation->context,
            );
        } else {
            UnusualStopDetected::dispatch(
                $deviation->rideId, $deviation->driverId, (int) $deviation->seconds,
                $deviation->position->lat, $deviation->position->lng, $deviation->detectedAt, $deviation->context,
            );
        }

        return $deviation;
    }
}
