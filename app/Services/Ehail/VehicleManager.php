<?php

namespace App\Services\Ehail;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A driver has any number of vehicles and at most one active at a time; the
 * active one is what the ride queue and accept check look at. Postgres and
 * SQLite also enforce "one active" with a partial unique index; this class is
 * the only guard on MySQL, and it always flips the old active vehicle off
 * before switching a new one on.
 */
class VehicleManager
{
    private const LIVE_RIDE_STATUSES = ['accepted', 'en_route', 'arrived', 'in_progress', 'needs_review'];

    /**
     * @param  array<string, mixed>  $attributes  make, model, year, color, plate_number, type, seats
     */
    public function add(DriverProfile $profile, array $attributes, bool $activate = false): Vehicle
    {
        return DB::transaction(function () use ($profile, $attributes, $activate) {
            $this->lock($profile);

            $makeActive = $activate || $profile->vehicles()->where('is_active', true)->doesntExist();

            if ($makeActive && $activate) {
                $this->assertNotOnALiveRide($profile, 'Finish your current ride before changing your active vehicle.');
            }

            if ($makeActive) {
                $profile->vehicles()->where('is_active', true)->update(['is_active' => false]);
            }

            return $profile->vehicles()->create($attributes + ['is_active' => $makeActive]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Vehicle $vehicle, array $attributes): Vehicle
    {
        if ($this->isOnALiveRide($vehicle)) {
            throw new VehicleRuleViolation('This vehicle is on a ride right now. Edit it once the ride is finished.');
        }

        $vehicle->update($attributes);

        return $vehicle;
    }

    public function activate(Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($vehicle) {
            $profile = $this->lock($vehicle->driverProfile()->withoutGlobalScope('user')->firstOrFail());

            if ($vehicle->is_active) {
                return $vehicle;
            }

            $this->assertNotOnALiveRide($profile, 'Finish your current ride before changing your active vehicle.');

            $profile->vehicles()->where('is_active', true)->update(['is_active' => false]);
            $vehicle->update(['is_active' => true]);

            return $vehicle;
        });
    }

    /**
     * Deactivating the active vehicle leaves the driver with nothing to drive,
     * so they go offline as well.
     */
    public function deactivate(Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($vehicle) {
            $profile = $this->lock($vehicle->driverProfile()->withoutGlobalScope('user')->firstOrFail());

            if (! $vehicle->is_active) {
                return $vehicle;
            }

            if ($this->isOnALiveRide($vehicle)) {
                throw new VehicleRuleViolation('This vehicle is on a ride right now. Deactivate it once the ride is finished.');
            }

            $vehicle->update(['is_active' => false]);
            $profile->forceFill(['is_online' => false])->save();

            return $vehicle;
        });
    }

    private function lock(DriverProfile $profile): DriverProfile
    {
        return DriverProfile::withoutGlobalScope('user')->whereKey($profile->id)->lockForUpdate()->firstOrFail();
    }

    private function isOnALiveRide(Vehicle $vehicle): bool
    {
        return Ride::where('vehicle_id', $vehicle->id)->whereIn('status', self::LIVE_RIDE_STATUSES)->exists();
    }

    private function assertNotOnALiveRide(DriverProfile $profile, string $message): void
    {
        $live = Ride::where('driver_id', $profile->user_id)->whereIn('status', self::LIVE_RIDE_STATUSES)->exists();

        if ($live) {
            throw new VehicleRuleViolation($message);
        }
    }
}
