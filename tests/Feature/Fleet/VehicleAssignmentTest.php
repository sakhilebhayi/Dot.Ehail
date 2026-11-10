<?php

namespace Tests\Feature\Fleet;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\VehicleAssignments;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;

class VehicleAssignmentTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, RefreshDatabase;

    public function test_a_permitted_driver_gets_the_vehicle_and_it_becomes_theirs_to_drive(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->fleetVehicle($fleet);

        $this->assertNull($vehicle->driver_profile_id);
        $this->assertSame('fleet', $vehicle->owned_by);

        $vehicle = $this->giveVehicle($fleet, $vehicle, $profile);

        $this->assertSame($profile->id, $vehicle->driver_profile_id);
        $this->assertTrue($vehicle->is_active, 'a driver with no other vehicle drives it straight away');
        $this->assertSame(1, FleetVehicleAssignment::query()->open()->where('vehicle_id', $vehicle->id)->count());
        $this->assertTrue(app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->allowed());
    }

    public function test_a_driver_without_permission_cannot_be_given_the_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        [, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->fleetVehicle($fleet);

        $this->expectException(FleetActionRefused::class);
        app(VehicleAssignments::class)->assign($fleet, $vehicle, $profile, User::find($fleet->owner_user_id));
    }

    public function test_a_driver_of_another_fleet_cannot_be_permitted_or_given_the_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        $other = $this->fleetOwnedBy(name: 'Other Fleet');
        [, $outsider] = $this->fleetDriver($other, 'Outside Driver');
        $vehicle = $this->fleetVehicle($fleet);

        try {
            app(VehicleAssignments::class)->grantPermit($fleet, $vehicle, $outsider, User::find($fleet->owner_user_id));
            $this->fail('outsider permitted');
        } catch (FleetActionRefused $e) {
            $this->assertSame('not_this_fleet', $e->reason);
        }
    }

    public function test_the_vehicle_cannot_change_hands_while_its_driver_is_online_in_it(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$first, $firstProfile] = $this->fleetDriver($fleet, 'First Driver');
        [, $secondProfile] = $this->fleetDriver($fleet, 'Second Driver');
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $firstProfile);
        $owner = User::find($fleet->owner_user_id);
        $assignments = app(VehicleAssignments::class);
        $assignments->grantPermit($fleet, $vehicle, $secondProfile, $owner);

        DriverAvailability::create(['user_id' => $first->id, 'vehicle_id' => $vehicle->id, 'state' => DriverAvailability::ONLINE, 'since' => now()]);

        foreach ([fn () => $assignments->assign($fleet, $vehicle, $secondProfile, $owner), fn () => $assignments->unassign($fleet, $vehicle, $owner)] as $attempt) {
            try {
                $attempt();
                $this->fail('vehicle changed hands under an online driver');
            } catch (FleetActionRefused $e) {
                $this->assertSame('vehicle_in_use', $e->reason);
            }
        }

        $this->assertSame($firstProfile->id, $vehicle->fresh()->driver_profile_id);

        // Offline: it changes hands, and its documents follow the vehicle to the new holder.
        DriverAvailability::where('user_id', $first->id)->update(['state' => DriverAvailability::OFFLINE]);
        $assignments->assign($fleet, $vehicle, $secondProfile, $owner);

        $this->assertSame($secondProfile->id, $vehicle->fresh()->driver_profile_id);
        $this->assertSame(1, FleetVehicleAssignment::query()->open()->where('vehicle_id', $vehicle->id)->count());
        $this->assertSame('reassigned', FleetVehicleAssignment::where('driver_profile_id', $firstProfile->id)->value('end_reason'));
        $this->assertSame(0, VerificationDocument::where('vehicle_id', $vehicle->id)->where('driver_profile_id', $firstProfile->id)->count());
        $this->assertSame('verified', app(\App\Domain\Verification\VerificationService::class)->vehicleStatus($vehicle->fresh())['status']);
        // The documents' uploader (who may open the files) is unchanged.
        $this->assertSame([$first->id], VerificationDocument::where('vehicle_id', $vehicle->id)->distinct()->pluck('user_id')->all());
    }

    public function test_the_database_refuses_a_second_open_assignment_for_one_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        [, $a] = $this->fleetDriver($fleet, 'Driver A');
        [, $b] = $this->fleetDriver($fleet, 'Driver B');
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $a);

        $this->expectException(UniqueConstraintViolationException::class);
        FleetVehicleAssignment::create(['fleet_id' => $fleet->id, 'vehicle_id' => $vehicle->id, 'driver_profile_id' => $b->id, 'active_vehicle_id' => $vehicle->id, 'started_at' => now()]);
    }

    public function test_a_drivers_own_car_cannot_be_assigned_by_the_fleet(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = \App\Models\DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();
        $vehicle = $profile->vehicles()->first();
        $fleet = $profile->fleet;

        $this->assertSame($fleet->id, $vehicle->fleet_id, 'a driver-registered vehicle belongs to the driver\'s fleet');
        $this->assertSame('driver', $vehicle->owned_by);

        $this->expectException(FleetActionRefused::class);
        app(VehicleAssignments::class)->grantPermit($fleet, $vehicle, $profile, User::find($fleet->owner_user_id));
    }

    public function test_a_permit_cannot_be_withdrawn_from_the_driver_who_has_the_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        [, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $profile);

        $this->expectException(FleetActionRefused::class);
        app(VehicleAssignments::class)->revokePermit($fleet, $vehicle, $profile, User::find($fleet->owner_user_id));
    }

    public function test_assigning_through_the_portal_and_taking_back(): void
    {
        $fleet = $this->fleetOwnedBy();
        [, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->fleetVehicle($fleet);
        $owner = User::find($fleet->owner_user_id);

        $this->actingAs($owner)->post(route('fleet.vehicles.assign', $vehicle), ['driver_profile_id' => $profile->id])
            ->assertSessionHasErrors('fleet');
        $this->actingAs($owner)->post(route('fleet.vehicles.permits.grant', $vehicle), ['driver_profile_id' => $profile->id])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('fleet.vehicles.assign', $vehicle), ['driver_profile_id' => $profile->id])->assertSessionHasNoErrors();
        $this->assertSame($profile->id, $vehicle->fresh()->driver_profile_id);

        $this->actingAs($owner)->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertSee('Thabo Mokoena')->assertSee('CA 100 200');

        $this->actingAs($owner)->post(route('fleet.vehicles.unassign', $vehicle))->assertSessionHasNoErrors();
        $this->assertNull($vehicle->fresh()->driver_profile_id);
        $this->assertFalse($vehicle->fresh()->is_active);
        $this->assertSame(0, FleetVehicleAssignment::query()->open()->count());
    }
}
