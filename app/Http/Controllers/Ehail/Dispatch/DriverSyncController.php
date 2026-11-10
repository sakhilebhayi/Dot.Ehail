<?php

namespace App\Http\Controllers\Ehail\Dispatch;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Dispatch\RideFeed;
use App\Domain\Geo\Deviation\DeviationMonitor;
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Geo\Locations\SampleOutcome;
use App\Domain\Geo\LocationSample;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * One request per driver poll (docs/R46_PERF.md section 2, D-1501): the phone's positions since the last sync go
 * up, the dispatch feed comes down. It replaces three requests of the first driver app (feed GET every 2 s,
 * location POST every 4 s, heartbeat every 10 s) with one every 3 s, through exactly the same domain code:
 * geo's screening and trail (LocationStore::recordMany, mirrored into dispatch's availability), the deviation
 * monitor, the dispatcher's on-demand tick and RideFeed::forDriver with the client's version.
 *
 * Response (always 200; a POST cannot be answered 304): {version, poll_after_s, feed: <driver feed>|null when
 * unchanged, location: {accepted, rejected, on_trip}|{error: not_online}|null}. Headers ETag, Retry-After.
 */
class DriverSyncController extends Controller
{
    use DispatchJson;

    public function __invoke(
        Request $request,
        DriverPresence $presence,
        LocationStore $store,
        DeviationMonitor $monitor,
        AvailabilityService $availability,
        Dispatcher $dispatcher,
        RideFeed $feed,
    ): JsonResponse {
        $driver = $request->user();
        $max = (int) config('ehail-geo.locations.max_samples_per_post', 20);

        $validator = Validator::make($request->all(), [
            'samples' => ['sometimes', 'array', 'max:'.$max],
            'samples.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'samples.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'samples.*.accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'samples.*.speed' => ['nullable', 'numeric', 'min:-1', 'max:200'],
            'samples.*.heading' => ['nullable', 'numeric', 'min:-360', 'max:720'],
            'samples.*.recorded_at' => ['required', 'integer', 'min:1000000000000', 'max:99999999999999'],
            'saver' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'validation_failed', 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        $data = $validator->validated();
        $location = null;

        if (! empty($data['samples'])) {
            $activeRide = $presence->activeRideFor($driver);

            if ($activeRide === null && ! $presence->isOnline($driver)) {
                // Same rule as POST /geo/driver/location: positions only while online or on a trip.
                $location = ['error' => 'not_online'];
            } else {
                $samples = array_map(fn (array $s) => LocationSample::fromInput($s), array_values($data['samples']));
                $outcomes = $store->recordMany($driver, $samples, $activeRide);
                $onTrip = $activeRide !== null && RideTracking::isOnTrip($activeRide);

                if ($onTrip && collect($outcomes)->contains(fn (SampleOutcome $o) => $o->tripPointStored)) {
                    $monitor->evaluate($activeRide);
                }

                $accepted = collect($outcomes)->where('accepted', true)->count();
                $location = ['accepted' => $accepted, 'rejected' => count($outcomes) - $accepted, 'on_trip' => $onTrip];
            }
        }

        // No accepted fresh position: the sync still proves the app is alive, written at most every few seconds.
        if (($location['accepted'] ?? 0) === 0) {
            $this->keepAlive($driver, $availability);
        }

        // Polling advances dispatch, exactly as the feed endpoint does.
        if ($offer = RideOffer::where('pending_driver_id', $driver->id)->first()) {
            $dispatcher->tick(Ride::find($offer->ride_id));
        }

        $dispatcher->tick();
        $result = $feed->forDriver($driver, $this->sinceVersion($request), (bool) ($data['saver'] ?? false));
        $retry = $result['poll'];

        return response()->json([
            'version' => $result['version'],
            'poll_after_s' => $retry,
            'feed' => $result['body'],
            'location' => $location,
        ])->withHeaders([
            'ETag' => '"v'.$result['version'].'"',
            'Retry-After' => (string) $retry,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function keepAlive(User $driver, AvailabilityService $availability): void
    {
        $row = DriverAvailability::where('user_id', $driver->id)->first(['state', 'last_heartbeat_at']);

        // Offline drivers have nothing to keep alive (and no row is created for them here).
        if ($row === null || $row->state === DriverAvailability::OFFLINE) {
            return;
        }

        $every = (int) config('ehail_perf.driver_poll.heartbeat_write_every_s', 20);

        if ($row->last_heartbeat_at === null || $row->last_heartbeat_at->diffInSeconds(now(), true) >= $every) {
            $availability->heartbeat($driver);
        }
    }
}
