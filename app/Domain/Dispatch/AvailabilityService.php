<?php

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEligibility;
use App\Domain\Dispatch\Contracts\DriverLocationSink;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\FeedVersion;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A driver's dispatch availability (offline / online / on_trip / paused).
 *
 *  - goOnline only when DriverEligibility (the safety domain's verification
 *    seam) approves the driver in that vehicle; the refusal carries reasons.
 *  - heartbeat records the latest position; a driver without a heartbeat
 *    (and position) for ehail_dispatch.heartbeat_stale_seconds is stale and
 *    is not offered rides, without being switched offline.
 *  - goOffline is refused while on a trip.
 *  - pause keeps the driver signed in but out of dispatch.
 *
 * DriverProfile::is_online is kept in step so the pre-R-46 pages and the
 * open-board policy read the same answer.
 */
class AvailabilityService
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly DriverLocationSink $sink,
    ) {}

    public function forDriver(User $driver): ?DriverAvailability
    {
        return DriverAvailability::where('user_id', $driver->id)->first();
    }

    public function goOnline(User $driver, ?Vehicle $vehicle = null): DriverAvailability
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();
        $vehicle ??= $profile?->activeVehicle();

        if ($vehicle === null) {
            throw new DispatchRefused('no_active_vehicle', 'Add and activate a vehicle before going online.');
        }

        $result = $this->eligibility->canGoOnline($driver, $vehicle);

        if (! $result->allowed) {
            throw new DispatchRefused($result->reasons[0] ?? 'not_eligible', implode(' ', $result->messages) ?: 'You cannot go online right now.', 403);
        }

        return DB::transaction(function () use ($driver, $vehicle, $profile) {
            $row = $this->lockedRow($driver);

            if ($row->state === DriverAvailability::ON_TRIP) {
                return $row;
            }

            $row->forceFill([
                'state' => DriverAvailability::ONLINE,
                'vehicle_id' => $vehicle->id,
                'since' => now(),
                'online_since' => $row->state === DriverAvailability::ONLINE ? ($row->online_since ?? now()) : now(),
            ])->save();

            $profile?->forceFill(['is_online' => true])->save();

            return $row;
        }, 3);
    }

    public function goOffline(User $driver): DriverAvailability
    {
        return DB::transaction(function () use ($driver) {
            $row = $this->lockedRow($driver);

            if ($row->state === DriverAvailability::ON_TRIP || $row->current_ride_id !== null) {
                throw new DispatchRefused('on_trip', 'Finish or hand back your current ride before going offline.');
            }

            $this->withdrawPendingOffer($driver, 'driver_went_offline');

            $row->forceFill(['state' => DriverAvailability::OFFLINE, 'since' => now(), 'online_since' => null])->save();

            DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->update(['is_online' => false]);

            return $row;
        }, 3);
    }

    public function pause(User $driver): DriverAvailability
    {
        return DB::transaction(function () use ($driver) {
            $row = $this->lockedRow($driver);

            if ($row->state !== DriverAvailability::ONLINE) {
                throw new DispatchRefused('not_online', 'You can only pause while online and not on a trip.');
            }

            $this->withdrawPendingOffer($driver, 'driver_paused');
            $row->forceFill(['state' => DriverAvailability::PAUSED, 'since' => now()])->save();

            return $row;
        }, 3);
    }

    public function resume(User $driver): DriverAvailability
    {
        $row = $this->forDriver($driver);

        if ($row === null || $row->state !== DriverAvailability::PAUSED) {
            throw new DispatchRefused('not_paused', 'You are not paused.');
        }

        $vehicle = $row->vehicle_id ? Vehicle::find($row->vehicle_id) : null;

        return $this->goOnline($driver, $vehicle);
    }

    /**
     * Record that the driver's app is alive, with its position when it has one.
     * Positions are stored only while the driver is online, paused or on a trip
     * (an offline driver's whereabouts are not needed and not kept).
     */
    public function heartbeat(User $driver, ?float $lat = null, ?float $lng = null, ?int $heading = null, ?float $speedMs = null, ?float $accuracyM = null): DriverAvailability
    {
        $row = DriverAvailability::firstOrCreate(['user_id' => $driver->id], ['state' => DriverAvailability::OFFLINE, 'since' => now()]);
        $now = now();
        $changes = ['last_heartbeat_at' => $now];

        if ($row->state !== DriverAvailability::OFFLINE && $lat !== null && $lng !== null) {
            $changes += [
                'last_lat' => round($lat, 7),
                'last_lng' => round($lng, 7),
                'heading' => $heading === null ? null : (($heading % 360) + 360) % 360,
                'last_location_at' => $now,
            ];
        }

        $row->forceFill($changes)->save();

        if (isset($changes['last_lat'])) {
            $this->sink->record($driver, $lat, $lng, $heading, $speedMs, $accuracyM, $now);
        }

        return $row;
    }

    /**
     * RideStateMachine, inside the assignment transaction.
     */
    public function markOnTrip(int $driverId, Ride $ride): void
    {
        $row = DriverAvailability::where('user_id', $driverId)->lockForUpdate()->first()
            ?? new DriverAvailability(['user_id' => $driverId]);

        $row->forceFill([
            'state' => DriverAvailability::ON_TRIP,
            'current_ride_id' => $ride->id,
            'vehicle_id' => $ride->vehicle_id ?? $row->vehicle_id,
            'since' => now(),
        ])->save();
    }

    /**
     * RideStateMachine, inside the transaction that ends or hands back a ride.
     */
    public function releaseFromRide(int $driverId, Ride $ride): void
    {
        $row = DriverAvailability::where('user_id', $driverId)->lockForUpdate()->first();

        if ($row === null || ($row->current_ride_id !== null && $row->current_ride_id !== $ride->id)) {
            return;
        }

        $row->forceFill([
            'state' => $row->state === DriverAvailability::ON_TRIP ? DriverAvailability::ONLINE : $row->state,
            'current_ride_id' => null,
            'last_trip_ended_at' => now(),
            'since' => now(),
        ])->save();
    }

    private function lockedRow(User $driver): DriverAvailability
    {
        DriverAvailability::firstOrCreate(['user_id' => $driver->id], ['state' => DriverAvailability::OFFLINE, 'since' => now()]);

        return DriverAvailability::where('user_id', $driver->id)->lockForUpdate()->firstOrFail();
    }

    private function withdrawPendingOffer(User $driver, string $reason): void
    {
        $offer = RideOffer::where('pending_driver_id', $driver->id)->first();

        if ($offer === null) {
            return;
        }

        $updated = RideOffer::whereKey($offer->id)->where('status', RideOffer::PENDING)->update([
            'status' => RideOffer::WITHDRAWN, 'pending_ride_id' => null, 'pending_driver_id' => null,
            'responded_at' => now(), 'reason' => $reason, 'updated_at' => now(), 'version' => FeedVersion::now(),
        ]);

        if ($updated === 1) {
            Ride::whereKey($offer->ride_id)->update(['pending_offer_expires_at' => null, 'next_dispatch_at' => now()]);
        }
    }
}
