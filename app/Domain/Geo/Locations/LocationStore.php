<?php

namespace App\Domain\Geo\Locations;

use App\Domain\Geo\Geometry\BoundingBox;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\LocationSample;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\TripPoint;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Driver positions. Two stores with different purposes:
 *  - driver_locations: ONE row per driver, the latest fresh fix, for matching and for the assigned passenger.
 *  - trip_points: the sampled trail, recorded only while the driver's ride is in progress, for distance,
 *    deviation and disputes; purged after the retention period.
 * A passenger's live position is never stored here (only a ride's pickup and drop-off points exist, on rides).
 */
class LocationStore
{
    /**
     * Screens and records samples from one driver, oldest first.
     *
     * @param  list<LocationSample>  $samples
     * @return list<SampleOutcome> in the order given
     */
    public function recordMany(User $driver, array $samples, ?Ride $activeRide): array
    {
        $order = array_keys($samples);
        usort($order, fn ($a, $b) => $samples[$a]->recordedAt <=> $samples[$b]->recordedAt);

        $outcomes = [];
        $latest = DriverLocation::query()->where('driver_id', $driver->id)->first();
        $previous = $latest ? new LocationSample($latest->position(), $latest->recorded_at, $latest->accuracy_m) : null;
        $onTrip = $activeRide !== null && RideTracking::isOnTrip($activeRide);
        $lastPoint = $onTrip ? TripPoint::query()->where('ride_id', $activeRide->id)->orderByDesc('recorded_at')->first() : null;
        $freshest = null;

        foreach ($order as $index) {
            $sample = $samples[$index];
            $reason = $this->screen($driver, $sample, $previous);

            if ($reason !== null) {
                $outcomes[$index] = new SampleOutcome(false, $reason);

                continue;
            }

            $previous = $sample;
            $tripPointStored = false;

            if ($onTrip && $this->worthStoring($sample, $lastPoint)) {
                $lastPoint = TripPoint::query()->create([
                    'ride_id' => $activeRide->id,
                    'driver_id' => $driver->id,
                    'lat' => $sample->position->lat,
                    'lng' => $sample->position->lng,
                    'accuracy_m' => $sample->accuracyM,
                    'speed_mps' => $sample->speedMps,
                    'heading_deg' => $sample->headingDeg,
                    'recorded_at' => $sample->recordedAt,
                    'received_at' => now(),
                ]);
                $tripPointStored = true;
            }

            $isFresh = $sample->recordedAt->diffInSeconds(now(), true) <= (int) config('ehail-geo.locations.max_age_seconds', 60);

            if ($isFresh) {
                $freshest = $sample;
            }

            $outcomes[$index] = new SampleOutcome(true, null, $isFresh, $tripPointStored);
        }

        if ($freshest !== null) {
            $this->upsertLatest($driver, $freshest);
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    public function record(User $driver, LocationSample $sample, ?Ride $activeRide): SampleOutcome
    {
        return $this->recordMany($driver, [$sample], $activeRide)[0];
    }

    /**
     * Latest rows keyed by driver id.
     *
     * @param  list<int>  $driverIds
     * @return Collection<int, DriverLocation>
     */
    public function latest(array $driverIds): Collection
    {
        if ($driverIds === []) {
            return collect();
        }

        return DriverLocation::query()->whereIn('driver_id', $driverIds)->get()->keyBy('driver_id');
    }

    /**
     * The recorded trail of a ride, oldest first.
     *
     * @return Collection<int, TripPoint>
     */
    public function trail(Ride $ride): Collection
    {
        return TripPoint::query()->where('ride_id', $ride->id)->orderBy('recorded_at')->orderBy('id')->get();
    }

    /**
     * Drivers whose latest fresh fix is within $radiusMetres, nearest first. A bounding-box query on the indexed
     * lat/lng columns narrows the rows (portable across SQLite, MySQL and PostgreSQL without PostGIS), then the
     * exact great-circle distance filters and sorts them in PHP.
     *
     * @param  array{only?: list<int>, exclude?: list<int>, max_age_seconds?: int, limit?: int}  $filters
     * @return list<NearbyDriver>
     */
    public function nearbyDrivers(LatLng $centre, float $radiusMetres, array $filters = []): array
    {
        $box = BoundingBox::radius($centre, $radiusMetres);
        $maxAge = (int) ($filters['max_age_seconds'] ?? config('ehail-geo.locations.nearby_max_age_seconds', 60));

        $rows = DriverLocation::query()
            ->whereBetween('lat', [$box->minLat, $box->maxLat])
            ->whereBetween('lng', [$box->minLng, $box->maxLng])
            ->where('recorded_at', '>=', now()->subSeconds($maxAge))
            ->when(isset($filters['only']), fn ($q) => $q->whereIn('driver_id', $filters['only']))
            ->when(! empty($filters['exclude']), fn ($q) => $q->whereNotIn('driver_id', $filters['exclude']))
            ->get();

        $nearby = $rows
            ->map(fn (DriverLocation $row) => new NearbyDriver($row->driver_id, Haversine::metres($centre, $row->position()), $row))
            ->filter(fn (NearbyDriver $d) => $d->distanceM <= $radiusMetres)
            ->sortBy(fn (NearbyDriver $d) => $d->distanceM)
            ->values();

        if (isset($filters['limit'])) {
            $nearby = $nearby->take((int) $filters['limit']);
        }

        return $nearby->all();
    }

    private function screen(User $driver, LocationSample $sample, ?LocationSample $previous): ?string
    {
        $config = config('ehail-geo.locations');
        $now = CarbonImmutable::now();

        if ($sample->accuracyM !== null && $sample->accuracyM > (float) $config['max_accuracy_m']) {
            return 'inaccurate';
        }

        if ($sample->recordedAt->greaterThan($now->addSeconds((int) $config['max_future_skew_seconds']))) {
            return 'future';
        }

        if ($sample->recordedAt->lessThan($now->subSeconds((int) $config['max_backfill_seconds']))) {
            return 'stale';
        }

        if ($previous === null) {
            return null;
        }

        if ($sample->recordedAt->lessThanOrEqualTo($previous->recordedAt)) {
            return 'out_of_order';
        }

        $seconds = $previous->recordedAt->diffInMilliseconds($sample->recordedAt, true) / 1000;

        // After a long silence any distance is plausible (the driver may have driven with the app closed).
        if ($seconds > 120) {
            $this->resetJumps($driver);

            return null;
        }

        $slack = ($sample->accuracyM ?? 20) + ($previous->accuracyM ?? 20);
        $distance = max(0.0, Haversine::metres($previous->position, $sample->position) - $slack);

        if ($distance / max($seconds, 0.5) > (float) $config['max_speed_mps']) {
            // If the previous fix was the wrong one (cold-start GPS), the driver must not be stuck forever:
            // after three consecutive jumps the new position is believed.
            $key = 'ehail-geo:jumps:'.$driver->id;
            Cache::add($key, 0, 300);
            $count = (int) Cache::increment($key);

            if ($count < 3) {
                return 'implausible_jump';
            }
        }

        $this->resetJumps($driver);

        return null;
    }

    private function resetJumps(User $driver): void
    {
        // Almost every accepted fix comes here with no jump counted: read first so the common case costs a select,
        // not a delete (a write) per fix on the database cache store (docs/R46_PERF.md, D-1505).
        $key = 'ehail-geo:jumps:'.$driver->id;

        if (Cache::get($key) !== null) {
            Cache::forget($key);
        }
    }

    private function worthStoring(LocationSample $sample, ?TripPoint $last): bool
    {
        if ($last === null) {
            return true;
        }

        $seconds = $last->recorded_at->diffInSeconds($sample->recordedAt, true);
        $metres = Haversine::metres($last->position(), $sample->position);

        return $seconds >= (int) config('ehail-geo.locations.trip_point_min_interval_seconds', 3)
            || $metres >= (float) config('ehail-geo.locations.trip_point_min_distance_m', 25);
    }

    private function upsertLatest(User $driver, LocationSample $sample): void
    {
        $now = now();

        DriverLocation::query()->upsert([[
            'driver_id' => $driver->id,
            'lat' => $sample->position->lat,
            'lng' => $sample->position->lng,
            'accuracy_m' => $sample->accuracyM,
            'speed_mps' => $sample->speedMps,
            'heading_deg' => $sample->headingDeg,
            'recorded_at' => $sample->recordedAt,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['driver_id'], ['lat', 'lng', 'accuracy_m', 'speed_mps', 'heading_deg', 'recorded_at', 'received_at', 'updated_at']);
    }
}
