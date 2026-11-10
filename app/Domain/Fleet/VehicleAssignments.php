<?php

namespace App\Domain\Fleet;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\Models\FleetVehiclePermit;
use App\Domain\Fleet\Seams\VehicleDocumentCustody;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Which drivers may drive which fleet vehicles (permits) and who holds each one now (assignments).
 *
 * Custody model: a fleet-held vehicle is driven by exactly one driver at a time because the vehicle row names its
 * holder (vehicles.driver_profile_id); dispatch, the go-online gate and the ride accept check already require the
 * driver to hold the vehicle they drive, so nothing else in the platform changes. The open assignment row mirrors
 * the holder and carries the history; its active_vehicle_id is unique, so two concurrent assignments of one
 * vehicle cannot both commit on any engine.
 *
 * Dispatch availability enforces the hand-over: a vehicle is never taken from a driver who is online, paused or on
 * a trip in it (they go offline first), and never while a ride in that vehicle is live.
 */
final class VehicleAssignments
{
    public function __construct(private readonly VehicleDocumentCustody $documents) {}

    public function grantPermit(Fleet $fleet, Vehicle $vehicle, DriverProfile $profile, User $actor): FleetVehiclePermit
    {
        $this->assertFleetVehicle($fleet, $vehicle);
        $this->assertFleetDriver($fleet, $profile);

        $permit = FleetVehiclePermit::query()->firstOrNew(['vehicle_id' => $vehicle->id, 'driver_profile_id' => $profile->id]);
        $permit->forceFill(['fleet_id' => $fleet->id, 'granted_by' => $actor->id, 'revoked_at' => null, 'revoked_by' => null])->save();

        AuditLog::record('fleet.vehicle_permit_granted', $actor, $vehicle, ['fleet_id' => $fleet->id, 'driver_profile_id' => $profile->id]);

        return $permit;
    }

    public function revokePermit(Fleet $fleet, Vehicle $vehicle, DriverProfile $profile, User $actor): void
    {
        $this->assertFleetVehicle($fleet, $vehicle);

        if ((int) $vehicle->driver_profile_id === (int) $profile->id) {
            throw new FleetActionRefused('holds_vehicle', 'This driver has the vehicle now. End the assignment first.');
        }

        FleetVehiclePermit::query()->where('vehicle_id', $vehicle->id)->where('driver_profile_id', $profile->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);

        AuditLog::record('fleet.vehicle_permit_revoked', $actor, $vehicle, ['fleet_id' => $fleet->id, 'driver_profile_id' => $profile->id]);
    }

    public function hasPermit(Vehicle $vehicle, DriverProfile $profile): bool
    {
        return FleetVehiclePermit::query()->active()->where('vehicle_id', $vehicle->id)->where('driver_profile_id', $profile->id)->exists();
    }

    /**
     * Give a fleet vehicle to a permitted driver. Idempotent for the driver who already holds it.
     */
    public function assign(Fleet $fleet, Vehicle $vehicle, DriverProfile $profile, User $actor): FleetVehicleAssignment
    {
        $this->assertFleetDriver($fleet, $profile);

        if ($profile->status !== 'approved') {
            throw new FleetActionRefused('driver_not_approved', 'Only an approved driver can be given a vehicle.');
        }

        if (! $this->hasPermit($vehicle, $profile)) {
            throw new FleetActionRefused('no_permit', 'Allow this driver to drive the vehicle first.');
        }

        try {
            return DB::transaction(function () use ($fleet, $vehicle, $profile, $actor) {
                // Serialise assignments per driver first: two vehicles given to one driver at once must see each
                // other's "active vehicle" decision. Without this row lock PostgreSQL (READ COMMITTED) let both
                // activate and the second failed on the one-active-vehicle constraint (found by the R-46 perf run).
                DriverProfile::withoutGlobalScope('user')->whereKey($profile->id)->lockForUpdate()->first();
                $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                $this->assertFleetVehicle($fleet, $locked);

                if ($locked->retired_at !== null) {
                    throw new FleetActionRefused('vehicle_retired', 'This vehicle is retired. Return it to the fleet first.');
                }

                $open = FleetVehicleAssignment::query()->open()->where('vehicle_id', $locked->id)->lockForUpdate()->first();

                if ($open !== null && (int) $open->driver_profile_id === (int) $profile->id && (int) $locked->driver_profile_id === (int) $profile->id) {
                    return $open;
                }

                if ($locked->driver_profile_id !== null) {
                    $this->assertNotInUse($locked);
                }

                if ($open !== null) {
                    $this->close($open, $actor, 'reassigned');
                }

                $assignment = FleetVehicleAssignment::query()->create([
                    'fleet_id' => $fleet->id,
                    'vehicle_id' => $locked->id,
                    'driver_profile_id' => $profile->id,
                    'active_vehicle_id' => $locked->id,
                    'assigned_by' => $actor->id,
                    'started_at' => now(),
                ]);

                // The new holder drives it straight away only when they have no other active vehicle and are not
                // online in one: never switch a working driver's vehicle under them.
                $activate = ! Vehicle::query()->where('driver_profile_id', $profile->id)->where('is_active', true)->exists()
                    && ! $this->isOnline($profile->user_id);

                $changed = Vehicle::query()->whereKey($locked->id)
                    ->where(fn ($q) => $locked->driver_profile_id === null
                        ? $q->whereNull('driver_profile_id')
                        : $q->where('driver_profile_id', $locked->driver_profile_id))
                    ->update(['driver_profile_id' => $profile->id, 'is_active' => $activate, 'updated_at' => now()]);

                if ($changed !== 1) {
                    throw new FleetActionRefused('assignment_conflict', 'Someone else changed this vehicle a moment ago. Reload and try again.', 409);
                }

                $this->documents->moveTo($locked, $profile);

                AuditLog::record('fleet.vehicle_assigned', $actor, $locked, ['fleet_id' => $fleet->id, 'driver_profile_id' => $profile->id, 'from_driver_profile_id' => $locked->driver_profile_id]);

                return $assignment;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new FleetActionRefused('assignment_conflict', 'This vehicle was just given to another driver. Reload and try again.', 409);
        }
    }

    /**
     * Take a fleet vehicle back from its driver (between drivers, nobody holds it).
     */
    public function unassign(Fleet $fleet, Vehicle $vehicle, User $actor, string $reason = 'returned'): void
    {
        DB::transaction(function () use ($fleet, $vehicle, $actor, $reason) {
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->assertFleetVehicle($fleet, $locked);

            if ($locked->driver_profile_id === null) {
                return;
            }

            $this->assertNotInUse($locked);

            FleetVehicleAssignment::query()->open()->where('vehicle_id', $locked->id)->get()
                ->each(fn (FleetVehicleAssignment $open) => $this->close($open, $actor, $reason));

            Vehicle::query()->whereKey($locked->id)->update(['driver_profile_id' => null, 'is_active' => false, 'updated_at' => now()]);

            AuditLog::record('fleet.vehicle_unassigned', $actor, $locked, ['fleet_id' => $fleet->id, 'driver_profile_id' => $locked->driver_profile_id, 'reason' => $reason]);
        }, 3);
    }

    /**
     * Whether someone is working in this vehicle right now: its holder is online, paused or on a trip in it, or a
     * ride in it is live.
     */
    public function inUse(Vehicle $vehicle): bool
    {
        $working = DriverAvailability::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('state', '!=', DriverAvailability::OFFLINE)
            ->exists();

        return $working || Ride::query()->where('vehicle_id', $vehicle->id)->whereIn('status', RideStatus::values(RideStatus::driverHoldingStates()))->exists();
    }

    private function isOnline(int $userId): bool
    {
        return DriverAvailability::query()->where('user_id', $userId)->where('state', '!=', DriverAvailability::OFFLINE)->exists();
    }

    private function assertNotInUse(Vehicle $vehicle): void
    {
        if ($this->inUse($vehicle)) {
            throw new FleetActionRefused('vehicle_in_use', 'The driver is online or on a trip in this vehicle. They need to go offline before it changes hands.', 409);
        }
    }

    private function close(FleetVehicleAssignment $open, User $actor, string $reason): void
    {
        $open->forceFill(['active_vehicle_id' => null, 'ended_at' => now(), 'ended_by' => $actor->id, 'end_reason' => $reason])->save();
    }

    private function assertFleetVehicle(Fleet $fleet, Vehicle $vehicle): void
    {
        if ((int) $vehicle->fleet_id !== (int) $fleet->id) {
            throw new FleetActionRefused('not_this_fleet', 'That vehicle is not in this fleet.', 404);
        }

        if (! $vehicle->isFleetOwned()) {
            throw new FleetActionRefused('driver_owned_vehicle', "This is a driver's own vehicle. Only vehicles the fleet holds can be assigned.");
        }
    }

    private function assertFleetDriver(Fleet $fleet, DriverProfile $profile): void
    {
        if ((int) $profile->fleet_id !== (int) $fleet->id) {
            throw new FleetActionRefused('not_this_fleet', 'That driver is not in this fleet.', 404);
        }
    }
}
