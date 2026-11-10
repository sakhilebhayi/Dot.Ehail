<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Templates;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Driver approaching": once per assignment, the first time a heartbeat puts the assigned driver
 * within config('ehail_comms.approaching_m') of the pickup (great-circle distance on the reported
 * position; no ETA is claimed). Runs on driver_availability updates, so it costs nothing while
 * nobody is on the way: a driver who is not on a trip, or whose position did not change, returns at
 * once, and a ride already announced is remembered in the cache.
 */
final class ApproachWatcher
{
    public function __construct(private readonly NotificationRouter $router, private readonly Templates $templates) {}

    public function availabilityUpdated(DriverAvailability $row): void
    {
        try {
            if ($row->state !== DriverAvailability::ON_TRIP || $row->current_ride_id === null || $row->last_lat === null
                || ! $row->wasChanged(['last_lat', 'last_lng'])) {
                return;
            }

            $rideId = (int) $row->current_ride_id;
            $doneKey = 'comms.approach.done.'.$rideId.'.'.(int) $row->user_id;

            if (Cache::get($doneKey) !== null) {
                return;
            }

            $ride = Ride::query()->find($rideId);

            if ($ride === null || ! in_array($ride->status, ['driver_assigned', 'driver_arriving'], true)
                || $ride->pickup_lat === null || $ride->pickup_lng === null || (int) $ride->driver_id !== (int) $row->user_id) {
                return;
            }

            $distance = self::metres((float) $row->last_lat, (float) $row->last_lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng);

            if ($distance > (int) config('ehail_comms.approaching_m', 500)) {
                return;
            }

            $message = $this->templates->driverApproaching($ride->load(['passenger', 'driver']), (int) $distance);

            if ($message !== null) {
                $this->router->send($message);
            }

            // The router is idempotent per assignment; the cache only saves the lookups.
            Cache::put($doneKey, true, now()->addHours(3));
        } catch (Throwable $e) {
            Log::warning('Comms approach check failed.', ['error' => class_basename($e)]);
        }
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
