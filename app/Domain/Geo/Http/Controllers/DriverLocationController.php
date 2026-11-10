<?php

namespace App\Domain\Geo\Http\Controllers;

use App\Domain\Geo\Deviation\DeviationMonitor;
use App\Domain\Geo\Http\Controllers\Concerns\ValidatesJson;
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\LocationAccess;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Geo\Locations\SampleOutcome;
use App\Domain\Geo\LocationSample;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
    use ValidatesJson;

    /**
     * A driver's device reports one sample or a short queue of samples (after a dead zone). Only an approved
     * driver who is online or on an active ride may report; throttled per driver (ehail-geo-location limiter).
     */
    public function store(Request $request, DriverPresence $presence, LocationStore $store, DeviationMonitor $monitor): JsonResponse
    {
        $driver = $request->user();
        $activeRide = $presence->activeRideFor($driver);

        if ($activeRide === null && ! $presence->isOnline($driver)) {
            return response()->json([
                'reason' => 'not_online',
                'message' => 'Go online to share your location. Your position is only collected while you are online or on a trip.',
            ], 409);
        }

        $max = (int) config('ehail-geo.locations.max_samples_per_post', 20);
        $data = $this->validateJson($request, [
            'samples' => ['required', 'array', 'min:1', 'max:'.$max],
            'samples.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'samples.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'samples.*.accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'samples.*.speed' => ['nullable', 'numeric', 'min:-1', 'max:200'],
            'samples.*.heading' => ['nullable', 'numeric', 'min:-360', 'max:720'],
            'samples.*.recorded_at' => ['required', 'integer', 'min:1000000000000', 'max:99999999999999'],
        ]);

        $samples = array_map(fn (array $s) => LocationSample::fromInput($s), array_values($data['samples']));
        $outcomes = $store->recordMany($driver, $samples, $activeRide);

        if ($activeRide !== null && RideTracking::isOnTrip($activeRide) && collect($outcomes)->contains(fn (SampleOutcome $o) => $o->tripPointStored)) {
            $monitor->evaluate($activeRide);
        }

        return response()->json([
            'accepted' => collect($outcomes)->where('accepted', true)->count(),
            'rejected' => collect($outcomes)->where('accepted', false)->count(),
            'outcomes' => array_map(fn (SampleOutcome $o) => $o->toArray(), $outcomes),
            'on_trip' => $activeRide !== null && RideTracking::isOnTrip($activeRide),
            'next_post_after_ms' => 4000,
        ]);
    }

    /**
     * The assigned driver's latest position for one ride. The passenger sees it only while the ride is active;
     * anyone without a part in the ride gets 404 so a ride's existence is not revealed.
     */
    public function forRide(Request $request, Ride $ride, LocationAccess $access, LocationStore $store): JsonResponse
    {
        $viewer = $request->user();

        if (! $access->canViewDriverOf($viewer, $ride)) {
            if ($viewer->id !== $ride->passenger_id) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return response()->json([
                'reason' => 'not_tracking',
                'message' => 'The driver\'s position is shared only while a driver is assigned and the ride is active.',
            ], 403);
        }

        $row = $store->latest([(int) $ride->driver_id])->get((int) $ride->driver_id);

        return response()->json([
            'ride_id' => $ride->id,
            'status' => $ride->status,
            'driver_location' => $row === null ? null : [
                'lat' => round($row->lat, 6),
                'lng' => round($row->lng, 6),
                'accuracy_m' => $row->accuracy_m !== null ? (int) round($row->accuracy_m) : null,
                'heading_deg' => $row->heading_deg !== null ? (int) round($row->heading_deg) : null,
                'speed_mps' => $row->speed_mps,
                'recorded_at' => $row->recorded_at->toIso8601String(),
                'age_seconds' => (int) $row->recorded_at->diffInSeconds(now(), true),
            ],
        ]);
    }
}
