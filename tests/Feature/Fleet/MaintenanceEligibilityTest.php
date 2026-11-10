<?php

namespace Tests\Feature\Fleet;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\FleetAlerts;
use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\Models\VehicleMaintenanceRecord;
use App\Domain\Rides\Events\TripCompleted;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\DriverNotEligibleToGoOnline;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;

class MaintenanceEligibilityTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, RefreshDatabase;

    public function test_out_of_service_blocks_going_online_in_that_vehicle_through_the_verification_gate(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $profile);
        $gate = app(DriverEligibility::class);
        $this->assertTrue($gate->canGoOnline($driver, $vehicle)->allowed());

        app(FleetVehicles::class)->takeOutOfService($fleet, $vehicle, 'Brakes in for repair', User::find($fleet->owner_user_id));

        $result = $gate->canGoOnline($driver, $vehicle->fresh());
        $this->assertFalse($result->allowed());
        $this->assertSame(['vehicle_out_of_service'], $result->codes());
        $this->assertStringContainsString('Brakes in for repair', $result->reasons[0]['message']);

        // The DriverProfile guard asks the same decorated gate.
        try {
            DriverProfile::withoutGlobalScope('user')->find($profile->id)->update(['is_online' => true]);
            $this->fail('went online in an out-of-service vehicle');
        } catch (DriverNotEligibleToGoOnline) {
            $this->assertFalse((bool) DriverProfile::withoutGlobalScope('user')->find($profile->id)->is_online);
        }

        app(FleetVehicles::class)->returnToService($fleet, $vehicle->fresh(), User::find($fleet->owner_user_id));
        $this->assertTrue($gate->canGoOnline($driver, $vehicle->fresh())->allowed());
    }

    public function test_a_driver_online_in_the_vehicle_is_taken_offline_and_one_on_a_trip_after_the_trip(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $profile);
        $owner = User::find($fleet->owner_user_id);

        DriverAvailability::create(['user_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'state' => DriverAvailability::ONLINE, 'since' => now()]);
        $outcome = app(FleetVehicles::class)->takeOutOfService($fleet, $vehicle, 'Tyre', $owner);
        $this->assertTrue($outcome['taken_offline']);
        $this->assertSame(DriverAvailability::OFFLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));

        // On a trip: the flag is recorded, the trip goes on, and the driver goes offline when it ends.
        app(FleetVehicles::class)->returnToService($fleet, $vehicle->fresh(), $owner);
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);
        DriverAvailability::where('user_id', $driver->id)->update(['state' => DriverAvailability::ON_TRIP, 'current_ride_id' => $ride->id]);

        $outcome = app(FleetVehicles::class)->takeOutOfService($fleet, $vehicle->fresh(), 'Warning light', $owner);
        $this->assertTrue($outcome['offline_after_trip']);
        $this->assertSame(DriverAvailability::ON_TRIP, DriverAvailability::where('user_id', $driver->id)->value('state'));

        // The trip ends (dispatch releases the driver back to online), then the fleet listener takes them offline.
        DriverAvailability::where('user_id', $driver->id)->update(['state' => DriverAvailability::ONLINE, 'current_ride_id' => null]);
        event(new TripCompleted($ride->id, $driver->id, 'driver', Carbon::now()));
        $this->assertSame(DriverAvailability::OFFLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));
    }

    public function test_dispatch_refuses_go_online_in_an_out_of_service_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $profile);
        app(FleetVehicles::class)->takeOutOfService($fleet, $vehicle, 'Service', User::find($fleet->owner_user_id));

        $this->assertTrue(app(\App\Domain\Dispatch\Contracts\DriverEligibility::class)->canGoOnline($driver, $vehicle)->allowed === false);

        try {
            app(AvailabilityService::class)->goOnline($driver, $vehicle->fresh());
            $this->fail('dispatch let the driver online in an out-of-service vehicle');
        } catch (DispatchRefused $refused) {
            $this->assertSame('vehicle_out_of_service', $refused->reason);
            $this->assertSame(403, $refused->status);
        }

        // Through the driver API as well.
        $this->actingAs($driver)->postJson('/api/ehail/driver/online', ['vehicle_id' => $vehicle->id])->assertStatus(403);
        $this->assertNotSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));

        app(FleetVehicles::class)->returnToService($fleet, $vehicle->fresh(), User::find($fleet->owner_user_id));
        $this->assertSame(DriverAvailability::ONLINE, app(AvailabilityService::class)->goOnline($driver, $vehicle->fresh())->state);
    }

    public function test_a_fleet_vehicle_without_an_open_assignment_to_the_driver_cannot_be_driven(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet);
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet), $profile);
        // Defence in depth: someone moved the holder without an assignment.
        FleetVehicleAssignment::query()->update(['active_vehicle_id' => null, 'ended_at' => now()]);

        $this->assertContains('fleet_vehicle_unassigned', app(DriverEligibility::class)->canGoOnline($driver, $vehicle->fresh())->codes());
    }

    public function test_next_service_by_date_and_odometer_and_the_alerts_it_raises(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $vehicles = app(FleetVehicles::class);
        $soon = $this->fleetVehicle($fleet, 'CA 1');
        $over = $this->fleetVehicle($fleet, 'CA 2');
        $fine = $this->fleetVehicle($fleet, 'CA 3');

        $vehicles->recordMaintenance($fleet, $soon, ['kind' => 'service', 'performed_on' => now()->subMonths(5)->toDateString(), 'odometer_km' => 50000, 'description' => 'Major service', 'next_service_due_on' => now()->addDays(5)->toDateString()], $owner);
        $vehicles->recordMaintenance($fleet, $over, ['kind' => 'service', 'performed_on' => now()->subMonth()->toDateString(), 'odometer_km' => 120500, 'description' => 'Minor service', 'next_service_due_km' => 120000], $owner);
        $vehicles->recordMaintenance($fleet, $fine, ['kind' => 'service', 'performed_on' => now()->toDateString(), 'odometer_km' => 10000, 'description' => 'Service', 'next_service_due_on' => now()->addMonths(6)->toDateString(), 'next_service_due_km' => 25000], $owner);

        $this->assertTrue($vehicles->nextService($soon->fresh())['due_soon']);
        $over = $over->fresh();
        $this->assertSame(120500, $over->odometer_km);
        $this->assertTrue($vehicles->nextService($over)['overdue']);
        $this->assertSame(-500, $vehicles->nextService($over)['km_left']);
        $this->assertFalse($vehicles->nextService($fine->fresh())['due_soon'] || $vehicles->nextService($fine->fresh())['overdue']);

        // An older reading never winds the odometer back.
        $vehicles->recordMaintenance($fleet, $over, ['kind' => 'repair', 'performed_on' => now()->toDateString(), 'odometer_km' => 1000, 'description' => 'Typo'], $owner);
        $this->assertSame(120500, $over->fresh()->odometer_km);

        $service = collect(app(FleetAlerts::class)->forFleet($owner, $fleet))->where('kind', 'service');
        $this->assertSame(['CA 2' => 'stop', 'CA 1' => 'caution'], $service->pluck('severity', 'subject')->all());
    }

    public function test_maintenance_is_recorded_through_the_portal_with_cost_in_minor_units(): void
    {
        $fleet = $this->fleetOwnedBy();
        $vehicle = $this->fleetVehicle($fleet);

        $this->actingAs(User::find($fleet->owner_user_id))->post(route('fleet.vehicles.maintenance.store', $vehicle), [
            'kind' => 'service', 'performed_on' => now()->toDateString(), 'odometer_km' => 42000,
            'description' => 'Oil and filters', 'cost' => '2450.50', 'next_service_due_km' => 57000,
        ])->assertSessionHasNoErrors();

        $record = VehicleMaintenanceRecord::sole();
        $this->assertSame(245050, $record->cost_minor);
        $this->assertSame(42000, $vehicle->fresh()->odometer_km);

        $this->actingAs(User::find($fleet->owner_user_id))->get(route('fleet.maintenance'))->assertOk()->assertSee('57 000 km')->assertSee(MoneyFormatter::format(245050, 'ZAR'), false);
    }
}
