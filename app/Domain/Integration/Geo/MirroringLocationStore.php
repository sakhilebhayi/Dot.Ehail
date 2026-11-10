<?php

namespace App\Domain\Integration\Geo;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\SampleOutcome;
use App\Domain\Geo\LocationSample;
use App\Models\Ride;
use App\Models\User;

/**
 * Geo's LocationStore, plus one integration step (D-704): a fresh fix accepted by geo's screening also
 * refreshes dispatch's availability row (latest position, heartbeat, feed version), so a driver app that
 * posts to /geo/driver/location is seen by dispatch and the passenger feed without a second request.
 * Positions are mirrored only while the driver is not offline (POPIA minimisation, dispatch section 3).
 * Heartbeats arrive the other way (dispatch -> DriverLocationSink -> here) and are not mirrored back.
 */
class MirroringLocationStore extends LocationStore
{
    private bool $mirror = true;

    public function recordMany(User $driver, array $samples, ?Ride $activeRide): array
    {
        $outcomes = parent::recordMany($driver, $samples, $activeRide);

        if ($this->mirror) {
            $this->mirrorFreshest($driver, array_values($samples), $outcomes);
        }

        return $outcomes;
    }

    /**
     * Record without mirroring (the caller already updated dispatch's availability).
     */
    public function recordFromDispatch(User $driver, LocationSample $sample, ?Ride $activeRide): SampleOutcome
    {
        $this->mirror = false;

        try {
            return $this->record($driver, $sample, $activeRide);
        } finally {
            $this->mirror = true;
        }
    }

    /**
     * @param  list<LocationSample>  $samples
     * @param  list<SampleOutcome>  $outcomes  same order as $samples
     */
    private function mirrorFreshest(User $driver, array $samples, array $outcomes): void
    {
        $freshest = null;

        foreach ($samples as $index => $sample) {
            if (($outcomes[$index] ?? null)?->latestUpdated && ($freshest === null || $sample->recordedAt->greaterThan($freshest->recordedAt))) {
                $freshest = $sample;
            }
        }

        if ($freshest === null) {
            return;
        }

        $row = DriverAvailability::query()->where('user_id', $driver->id)->first();

        if ($row === null || $row->state === DriverAvailability::OFFLINE) {
            return;
        }

        $row->forceFill([
            'last_lat' => round($freshest->position->lat, 7),
            'last_lng' => round($freshest->position->lng, 7),
            'heading' => $freshest->headingDeg === null ? $row->heading : ((int) round($freshest->headingDeg)) % 360,
            'last_location_at' => $freshest->recordedAt,
            'last_heartbeat_at' => now(),
        ])->save();
    }
}
