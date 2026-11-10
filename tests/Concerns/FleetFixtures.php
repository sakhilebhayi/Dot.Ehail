<?php

namespace Tests\Concerns;

use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\VehicleAssignments;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Builders for the fleet tests, through the real services (no stubs of the code under test).
 */
trait FleetFixtures
{
    use CreatesSafetyFixtures;

    protected function fleetOwnedBy(?User $owner = null, string $status = 'approved', string $name = 'Soweto Cabs'): Fleet
    {
        $owner ??= User::factory()->withPersonalTeam()->create();

        return Fleet::create(['name' => $name, 'owner_user_id' => $owner->id, 'status' => $status, 'kind' => 'company']);
    }

    /**
     * An approved driver in the fleet, driver documents approved, with no vehicle of their own.
     *
     * @return array{0: User, 1: DriverProfile}
     */
    protected function fleetDriver(Fleet $fleet, string $name = 'Thabo Mokoena', string $status = 'approved'): array
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $profile = DriverProfile::factory()->for($user)->create(['fleet_id' => $fleet->id, 'status' => $status]);

        foreach (app(\App\Domain\Verification\DocumentRequirements::class)->required('driver', null) as $type => $rule) {
            $this->approvedDocument($profile, $type, null, $rule['expiry_required'] ? now()->addYear()->toDateString() : null);
        }

        return [$user, $profile];
    }

    protected function fleetVehicle(Fleet $fleet, string $plate = 'CA 100 200', string $type = 'standard'): Vehicle
    {
        return app(FleetVehicles::class)->register($fleet, [
            'make' => 'Toyota', 'model' => 'Corolla Quest', 'year' => 2022, 'color' => 'White',
            'plate_number' => $plate, 'type' => $type, 'seats' => 4, 'doors' => 4, 'features' => [],
        ], User::find($fleet->owner_user_id));
    }

    /**
     * Permit and assign, then approve the vehicle's documents with its new holder.
     */
    protected function giveVehicle(Fleet $fleet, Vehicle $vehicle, DriverProfile $profile, bool $documents = true): Vehicle
    {
        $owner = User::find($fleet->owner_user_id);
        $assignments = app(VehicleAssignments::class);
        $assignments->grantPermit($fleet, $vehicle, $profile, $owner);
        $assignments->assign($fleet, $vehicle, $profile, $owner);
        $vehicle->refresh();

        if ($documents) {
            foreach (app(\App\Domain\Verification\DocumentRequirements::class)->required('vehicle', $vehicle->type) as $type => $rule) {
                $this->approvedDocument($profile, $type, $vehicle, $rule['expiry_required'] ? now()->addYear()->toDateString() : null);
            }
        }

        return $vehicle;
    }
}
