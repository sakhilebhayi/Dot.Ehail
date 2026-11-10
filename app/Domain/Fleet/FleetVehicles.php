<?php

namespace App\Domain\Fleet;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\Models\VehicleMaintenanceRecord;
use App\Models\AuditLog;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fleet-held vehicles: register, edit details (including doors and features the ride catalogue reads), retire,
 * maintenance records and the out-of-service flag.
 *
 * Out of service blocks going online in that vehicle (FleetAwareDriverEligibility). If the holder is online or
 * paused in it they are taken offline at once; if they are on a trip, the trip finishes and they are taken offline
 * when it ends (ReleaseOutOfServiceVehicles).
 */
final class FleetVehicles
{
    /** Vehicle features the ride catalogue understands (docs/R46_PRICING.md, category_vehicle_rules). */
    public const FEATURES = [
        'wheelchair_access' => 'Wheelchair access (ramp or lift, secured wheelchair space)',
        'child_seat' => 'Child seat available',
        'air_conditioning' => 'Air conditioning',
    ];

    public function __construct(
        private readonly VehicleAssignments $assignments,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @param  array{make: string, model: string, year: int, color: string, plate_number: string, type: string, seats: int, doors?: ?int, features?: list<string>, odometer_km?: ?int}  $attributes
     */
    public function register(Fleet $fleet, array $attributes, User $actor): Vehicle
    {
        $vehicle = new Vehicle;
        $vehicle->forceFill([
            'driver_profile_id' => null,
            'fleet_id' => $fleet->id,
            'owned_by' => 'fleet',
            'is_active' => false,
        ] + $this->clean($attributes))->save();

        AuditLog::record('fleet.vehicle_registered', $actor, $vehicle, ['fleet_id' => $fleet->id]);

        return $vehicle;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Fleet $fleet, Vehicle $vehicle, array $attributes, User $actor): Vehicle
    {
        $this->assertInFleet($fleet, $vehicle);

        if ($this->assignments->inUse($vehicle) && array_intersect(array_keys($attributes), ['type', 'plate_number', 'seats']) !== []) {
            throw new FleetActionRefused('vehicle_in_use', 'This vehicle is working right now. Change its class, plate or seats once the driver is offline.', 409);
        }

        $vehicle->forceFill($this->clean($attributes))->save();
        AuditLog::record('fleet.vehicle_updated', $actor, $vehicle, ['fleet_id' => $fleet->id, 'fields' => array_keys($attributes)]);

        return $vehicle;
    }

    public function retire(Fleet $fleet, Vehicle $vehicle, User $actor): Vehicle
    {
        $this->assertInFleet($fleet, $vehicle);

        if (! $vehicle->isFleetOwned()) {
            throw new FleetActionRefused('driver_owned_vehicle', "This is a driver's own vehicle; the driver manages it.");
        }

        $this->assignments->unassign($fleet, $vehicle, $actor, 'retired');
        $vehicle->refresh()->forceFill(['retired_at' => now()])->save();
        AuditLog::record('fleet.vehicle_retired', $actor, $vehicle, ['fleet_id' => $fleet->id]);

        return $vehicle;
    }

    public function reinstate(Fleet $fleet, Vehicle $vehicle, User $actor): Vehicle
    {
        $this->assertInFleet($fleet, $vehicle);
        $vehicle->forceFill(['retired_at' => null])->save();
        AuditLog::record('fleet.vehicle_reinstated', $actor, $vehicle, ['fleet_id' => $fleet->id]);

        return $vehicle;
    }

    /**
     * @return array{vehicle: Vehicle, taken_offline: bool, offline_after_trip: bool}
     */
    public function takeOutOfService(Fleet $fleet, Vehicle $vehicle, string $reason, User $actor): array
    {
        $this->assertInFleet($fleet, $vehicle);

        $vehicle->forceFill(['out_of_service_at' => now(), 'out_of_service_reason' => mb_substr($reason, 0, 200), 'out_of_service_by' => $actor->id])->save();
        AuditLog::record('fleet.vehicle_out_of_service', $actor, $vehicle, ['fleet_id' => $fleet->id, 'reason' => $reason]);

        $outcome = $this->releaseDriverOf($vehicle);

        return ['vehicle' => $vehicle] + $outcome;
    }

    public function returnToService(Fleet $fleet, Vehicle $vehicle, User $actor): Vehicle
    {
        $this->assertInFleet($fleet, $vehicle);
        $vehicle->forceFill(['out_of_service_at' => null, 'out_of_service_reason' => null, 'out_of_service_by' => null])->save();
        AuditLog::record('fleet.vehicle_back_in_service', $actor, $vehicle, ['fleet_id' => $fleet->id]);

        return $vehicle;
    }

    /**
     * Take the driver working in an out-of-service vehicle offline when they are not on a trip.
     *
     * @return array{taken_offline: bool, offline_after_trip: bool}
     */
    public function releaseDriverOf(Vehicle $vehicle): array
    {
        $row = DriverAvailability::query()->where('vehicle_id', $vehicle->id)->where('state', '!=', DriverAvailability::OFFLINE)->first();

        if ($row === null) {
            return ['taken_offline' => false, 'offline_after_trip' => false];
        }

        if ($row->state === DriverAvailability::ON_TRIP || $row->current_ride_id !== null) {
            return ['taken_offline' => false, 'offline_after_trip' => true];
        }

        $driver = User::query()->find($row->user_id);

        try {
            $this->availability->goOffline($driver);
        } catch (DispatchRefused) {
            return ['taken_offline' => false, 'offline_after_trip' => true];
        }

        AuditLog::record('driver.forced_offline', null, $vehicle, ['reason' => 'vehicle_out_of_service', 'driver_id' => $driver->id]);

        return ['taken_offline' => true, 'offline_after_trip' => false];
    }

    /**
     * @param  array{kind: string, performed_on: string, odometer_km?: ?int, description: string, cost_minor?: ?int, next_service_due_on?: ?string, next_service_due_km?: ?int}  $data
     */
    public function recordMaintenance(Fleet $fleet, Vehicle $vehicle, array $data, User $actor): VehicleMaintenanceRecord
    {
        $this->assertInFleet($fleet, $vehicle);

        return DB::transaction(function () use ($fleet, $vehicle, $data, $actor) {
            $record = VehicleMaintenanceRecord::query()->create([
                'fleet_id' => $fleet->id,
                'vehicle_id' => $vehicle->id,
                'kind' => $data['kind'],
                'performed_on' => $data['performed_on'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'description' => $data['description'],
                'cost_minor' => $data['cost_minor'] ?? null,
                'currency' => (string) config('money.currency', 'ZAR'),
                'next_service_due_on' => $data['next_service_due_on'] ?? null,
                'next_service_due_km' => $data['next_service_due_km'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            // The odometer only moves forward.
            if (($data['odometer_km'] ?? null) !== null && ($vehicle->odometer_km === null || $data['odometer_km'] > $vehicle->odometer_km)) {
                $vehicle->forceFill(['odometer_km' => $data['odometer_km']])->save();
            }

            return $record;
        });
    }

    /**
     * When the next service is due for a vehicle, from its latest record that says so.
     *
     * @return array{on: ?Carbon, km: ?int, km_left: ?int, overdue: bool, due_soon: bool, last: ?VehicleMaintenanceRecord}
     */
    public function nextService(Vehicle $vehicle, int $soonDays = 14, int $soonKm = 1000): array
    {
        $last = VehicleMaintenanceRecord::query()->where('vehicle_id', $vehicle->id)
            ->where(fn ($q) => $q->whereNotNull('next_service_due_on')->orWhereNotNull('next_service_due_km'))
            ->orderByDesc('performed_on')->orderByDesc('id')->first();

        $on = $last?->next_service_due_on;
        $km = $last?->next_service_due_km;
        $kmLeft = ($km !== null && $vehicle->odometer_km !== null) ? $km - $vehicle->odometer_km : null;
        $overdue = ($on !== null && $on->lt(today())) || ($kmLeft !== null && $kmLeft < 0);
        $soon = ! $overdue && (($on !== null && $on->lte(today()->addDays($soonDays))) || ($kmLeft !== null && $kmLeft <= $soonKm));

        return ['on' => $on, 'km' => $km, 'km_left' => $kmLeft, 'overdue' => $overdue, 'due_soon' => $soon, 'last' => $last];
    }

    public function currentAssignment(Vehicle $vehicle): ?FleetVehicleAssignment
    {
        return FleetVehicleAssignment::query()->open()->where('vehicle_id', $vehicle->id)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        $allowed = array_intersect_key($attributes, array_flip(['make', 'model', 'year', 'color', 'plate_number', 'type', 'seats', 'doors', 'features', 'odometer_km']));

        if (array_key_exists('features', $allowed)) {
            $allowed['features'] = array_values(array_intersect((array) $allowed['features'], array_keys(self::FEATURES)));
        }

        return $allowed;
    }

    private function assertInFleet(Fleet $fleet, Vehicle $vehicle): void
    {
        if ((int) $vehicle->fleet_id !== (int) $fleet->id) {
            throw new FleetActionRefused('not_this_fleet', 'That vehicle is not in this fleet.', 404);
        }
    }
}
