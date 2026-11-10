<?php

namespace Tests\Feature\Fleet;

use App\Domain\Fleet\FleetRegistration;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;

/**
 * Who sees what: owners and managers see only their fleet's drivers, vehicles, trips and earnings; managers only
 * their areas; operators read but do not write; passengers appear by first name only; identity numbers never by
 * default.
 */
class FleetIsolationTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, RefreshDatabase;

    private const PAGES = ['fleet.home', 'fleet.drivers', 'fleet.vehicles', 'fleet.maintenance', 'fleet.documents', 'fleet.earnings', 'fleet.trips', 'fleet.alerts', 'fleet.settings'];

    /**
     * @return array{a: \App\Models\Fleet, b: \App\Models\Fleet, ownerA: User, ownerB: User, manager: User, operator: User, stranger: User, vehicleA: Vehicle, vehicleB: Vehicle}
     */
    private function world(): array
    {
        $a = $this->fleetOwnedBy(name: 'Alpha Cabs');
        $b = $this->fleetOwnedBy(name: 'Bravo Taxis');
        [$driverA, $profileA] = $this->fleetDriver($a, 'Alpha Driver');
        [$driverB, $profileB] = $this->fleetDriver($b, 'Bravo Driver');
        $vehicleA = $this->giveVehicle($a, $this->fleetVehicle($a, 'ALPHA 1 GP'), $profileA);
        $vehicleB = $this->giveVehicle($b, $this->fleetVehicle($b, 'BRAVO 2 GP'), $profileB);

        $passenger = User::factory()->create(['name' => 'Lerato Passenger-Surname']);
        foreach ([[$driverA, $vehicleA], [$driverB, $vehicleB]] as [$driver, $vehicle]) {
            $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'completed_at' => now(), 'final_fare' => 99, 'pickup_address' => '7 Secret Home Road'], $passenger);
            Ride::query()->update(['active_passenger_id' => null]);
        }

        $manager = User::factory()->withPersonalTeam()->create(['email' => 'manager@example.test']);
        app(FleetRegistration::class)->addManager($a, 'manager@example.test', ['vehicles', 'maintenance'], User::find($a->owner_user_id));

        return [
            'a' => $a, 'b' => $b, 'ownerA' => User::find($a->owner_user_id), 'ownerB' => User::find($b->owner_user_id),
            'manager' => $manager, 'operator' => $this->makeOperator(), 'stranger' => User::factory()->withPersonalTeam()->create(),
            'vehicleA' => $vehicleA, 'vehicleB' => $vehicleB,
        ];
    }

    public function test_the_owner_sees_every_page_of_their_fleet_and_nothing_of_another(): void
    {
        $w = $this->world();

        foreach (self::PAGES as $page) {
            $response = $this->actingAs($w['ownerA'])->get(route($page, ['fleet' => $w['a']->id]))->assertOk();
            $response->assertDontSee('Bravo Driver')->assertDontSee('BRAVO 2 GP')->assertDontSee('Bravo Taxis');
        }

        // Asking for another fleet is refused, and so is any of its objects through this fleet's pages.
        $this->actingAs($w['ownerA'])->get(route('fleet.vehicles', ['fleet' => $w['b']->id]))->assertNotFound();
        $this->actingAs($w['ownerA'])->get(route('fleet.vehicles.show', $w['vehicleB']))->assertNotFound();
        $this->actingAs($w['ownerA'])->post(route('fleet.vehicles.unassign', $w['vehicleB']))->assertNotFound();
        $profileB = \App\Models\DriverProfile::withoutGlobalScope('user')->where('fleet_id', $w['b']->id)->first();
        $this->actingAs($w['ownerA'])->get(route('fleet.drivers.show', $profileB->id))->assertNotFound();
        $this->assertNotNull($w['vehicleB']->fresh()->driver_profile_id);
    }

    public function test_trips_show_the_passengers_first_name_only_and_no_places(): void
    {
        $w = $this->world();

        $this->actingAs($w['ownerA'])->get(route('fleet.trips'))->assertOk()
            ->assertSee('Lerato')
            ->assertDontSee('Passenger-Surname')
            ->assertDontSee('7 Secret Home Road')
            ->assertDontSee('20 Long Street');
    }

    public function test_identity_numbers_never_appear_by_default_and_documents_are_status_only(): void
    {
        $w = $this->world();
        $profileA = \App\Models\DriverProfile::withoutGlobalScope('user')->where('fleet_id', $w['a']->id)->first();
        $pending = \App\Models\DriverProfile::factory()->create(['fleet_id' => $w['a']->id, 'status' => 'pending', 'license_number' => 'LIC-PENDING-4455']);

        foreach ([route('fleet.drivers'), route('fleet.drivers.show', $profileA->id), route('fleet.documents', ['show' => 'all'])] as $url) {
            $this->actingAs($w['ownerA'])->get($url)->assertOk()
                ->assertDontSee($profileA->license_number)->assertDontSee('LIC-PENDING-4455')->assertDontSee('•••• 4455')
                ->assertDontSee('verification/'.$profileA->id);
        }

        $w['a']->forceFill(['identity_review_granted_at' => now()])->save();
        $this->actingAs($w['ownerA'])->get(route('fleet.drivers'))->assertSee('•••• 4455')->assertDontSee('LIC-PENDING-4455');
        // Managers never, even when the owner was granted the right.
        $this->actingAs($w['manager'])->get(route('fleet.vehicles'))->assertOk();
        $this->assertFalse(app(\App\Domain\Fleet\FleetAccess::class)->seesMaskedIdentity($w['manager'], $w['a']));
    }

    public function test_a_manager_sees_only_the_areas_they_were_given(): void
    {
        $w = $this->world();

        foreach (['fleet.home', 'fleet.vehicles', 'fleet.maintenance', 'fleet.alerts'] as $page) {
            $this->actingAs($w['manager'])->get(route($page))->assertOk();
        }
        foreach (['fleet.drivers', 'fleet.earnings', 'fleet.trips', 'fleet.documents', 'fleet.settings'] as $page) {
            $this->actingAs($w['manager'])->get(route($page))->assertForbidden();
        }
        $this->actingAs($w['manager'])->post(route('fleet.settings.managers.store'), ['email' => 'x@example.test', 'scopes' => ['vehicles']])->assertForbidden();
        $this->actingAs($w['manager'])->get(route('fleet.vehicles'))->assertSee('ALPHA 1 GP')->assertDontSee('BRAVO 2 GP');

        // Revoked: the fleet is gone for them.
        $member = \App\Domain\Fleet\Models\FleetMember::where('user_id', $w['manager']->id)->sole();
        app(FleetRegistration::class)->revokeManager($w['a'], $member, $w['ownerA']);
        $this->actingAs($w['manager'])->get(route('fleet.vehicles', ['fleet' => $w['a']->id]))->assertNotFound();
    }

    public function test_operators_read_any_fleet_but_change_nothing_here_and_strangers_get_nothing(): void
    {
        $w = $this->world();

        foreach (['fleet.home', 'fleet.vehicles', 'fleet.drivers', 'fleet.earnings'] as $page) {
            $this->actingAs($w['operator'])->get(route($page, ['fleet' => $w['b']->id]))->assertOk();
        }
        $this->actingAs($w['operator'])->post(route('fleet.vehicles.unassign', $w['vehicleB']))->assertForbidden();
        $this->actingAs($w['operator'])->patch(route('fleet.settings.update'), ['name' => 'Hijack'])->assertForbidden();

        $this->actingAs($w['stranger'])->get(route('fleet.home'))->assertOk()->assertSee('Register a fleet');
        foreach (['fleet.vehicles', 'fleet.drivers', 'fleet.earnings', 'fleet.trips'] as $page) {
            $this->actingAs($w['stranger'])->get(route($page, ['fleet' => $w['a']->id]))->assertNotFound();
        }
        $this->actingAs($w['stranger'])->post(route('fleet.ops.identity-review', $w['a']), ['granted' => 1])->assertForbidden();
        $this->actingAs($w['operator'])->post(route('fleet.ops.identity-review', $w['a']), ['granted' => 1])->assertRedirect();
        $this->assertTrue($w['a']->fresh()->identityReviewGranted());
    }

    public function test_while_a_fleet_is_pending_its_owner_sees_no_other_drivers_records(): void
    {
        $fleet = $this->fleetOwnedBy(status: 'pending');
        [$driver] = $this->fleetDriver($fleet, 'Hidden Applicant');
        $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'completed_at' => now(), 'final_fare' => 50]);
        $owner = User::find($fleet->owner_user_id);

        $this->actingAs($owner)->get(route('fleet.home'))->assertOk()->assertSee('In review');
        $this->actingAs($owner)->get(route('fleet.trips'))->assertOk()->assertDontSee('Hidden Applicant');
        $this->actingAs($owner)->get(route('fleet.documents', ['show' => 'all']))->assertOk()->assertDontSee('Hidden Applicant');
        $this->actingAs($owner)->get(route('fleet.drivers'))->assertOk()->assertDontSee('Applicant</a>', false);
    }
}
