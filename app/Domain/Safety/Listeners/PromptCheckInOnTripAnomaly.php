<?php

namespace App\Domain\Safety\Listeners;

use App\Domain\Safety\CheckInService;
use App\Models\Ride;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consumes the geo domain's App\Domain\Geo\Events\RouteDeviationDetected and
 * UnusualStopDetected (registered by class name in EhailSafetyServiceProvider, so this works
 * before and after the geo branch is merged). Reads the ride from a public `ride` (Ride) or
 * `rideId` / `ride_id` property, and forwards any other public scalar properties as context.
 */
class PromptCheckInOnTripAnomaly
{
    public function __construct(private readonly CheckInService $checkIns) {}

    public function handle(object $event): void
    {
        try {
            $ride = $this->rideFrom($event);

            if ($ride === null) {
                return;
            }

            $trigger = str_contains(class_basename($event), 'UnusualStop') ? 'unusual_stop' : 'route_deviation';
            $context = collect(get_object_vars($event))
                ->reject(fn ($value, $key) => in_array($key, ['ride', 'rideId', 'ride_id', 'socket'], true))
                ->filter(fn ($value) => is_scalar($value) || $value === null)
                ->all();

            $this->checkIns->prompt($ride, $trigger, $context);
        } catch (Throwable $e) {
            Log::error('Safety could not handle a trip anomaly event.', ['event' => $event::class, 'error' => class_basename($e)]);
        }
    }

    private function rideFrom(object $event): ?Ride
    {
        $ride = $event->ride ?? null;

        if ($ride instanceof Ride) {
            return $ride;
        }

        $id = $event->rideId ?? $event->ride_id ?? (is_numeric($ride) ? $ride : null);

        return is_numeric($id) ? Ride::find((int) $id) : null;
    }
}
