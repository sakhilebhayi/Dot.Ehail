<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\DriverLocationSink;
use App\Domain\Geo\Deviation\DeviationMonitor;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Geo\LocationSample;
use App\Domain\Integration\Geo\MirroringLocationStore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A dispatch heartbeat's position goes through geo's screening into the latest-position row and, during a
 * trip, the trip trail (billable distance, deviation and unusual-stop detection), exactly as a post to
 * /geo/driver/location would.
 */
class GeoLocationSink implements DriverLocationSink
{
    public function __construct(
        private readonly LocationStore $store,
        private readonly DriverPresence $presence,
        private readonly DeviationMonitor $monitor,
    ) {}

    public function record(User $driver, float $lat, float $lng, ?int $heading, ?float $speedMs, ?float $accuracyM, Carbon $recordedAt): void
    {
        $sample = new LocationSample(new LatLng($lat, $lng), CarbonImmutable::instance($recordedAt)->utc(), $accuracyM, $speedMs, $heading === null ? null : (float) $heading);
        $ride = $this->presence->activeRideFor($driver);

        $outcome = $this->store instanceof MirroringLocationStore
            ? $this->store->recordFromDispatch($driver, $sample, $ride)
            : $this->store->record($driver, $sample, $ride);

        if ($ride !== null && $outcome->tripPointStored && RideTracking::isOnTrip($ride)) {
            $this->monitor->evaluate($ride);
        }
    }
}
