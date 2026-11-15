<?php

namespace Tests\Feature\Search;

use App\Domain\Fleet\Models\FleetMember;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Passenger\Models\SavedPlace;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-52: the header search of every signed-in shell. What it searches follows who the person is: a rider's own trips and
 * places, a driver's own trips, a fleet's own vehicles and drivers, an operator's rides, drivers, vehicles and fleets (each
 * only where the ops role allows it), never another person's data, never an e-mail address or a phone number.
 */
class HeaderSearchTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, OpsFixtures, RefreshDatabase;

    /** @return array<string, mixed> */
    private function suggest(User $user, string $q, ?string $scope = null, int $status = 200): array
    {
        $response = $this->actingAs($user)->getJson(route('search.suggest', array_filter(['q' => $q, 'scope' => $scope])));
        $response->assertStatus($status);

        return $status === 200 ? $response->json() : [];
    }

    /** @return list<string> every row label and code of the answer, flattened */
    private function texts(array $json): array
    {
        $out = [];
        foreach ($json['groups'] ?? [] as $group) {
            foreach ($group['rows'] as $row) {
                $out[] = $row['label'].' '.($row['code'] ?? '').' '.($row['tag'] ?? '');
            }
        }

        return $out;
    }

    private function group(array $json, string $key): ?array
    {
        foreach ($json['groups'] ?? [] as $group) {
            if (($group['key'] ?? null) === $key) {
                return $group;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- everyone

    public function test_a_guest_is_sent_to_sign_in_for_both_the_suggestions_and_the_page(): void
    {
        $this->getJson(route('search.suggest', ['q' => 'kloof']))->assertUnauthorized();
        $this->get(route('search', ['q' => 'kloof']))->assertRedirect(route('login'));
    }

    public function test_a_short_or_wildcard_only_query_searches_nothing_and_a_wildcard_is_not_a_pattern(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $this->makeRide(['pickup_address' => '10 Kloof Street'], $rider);

        foreach (['', 'k', '%', '__', '\\', '%%%'] as $q) {
            $this->assertSame([], $this->suggest($rider, $q)['groups'], 'q='.$q);
        }
        $this->assertSame([], $this->suggest($rider, 'K%f')['groups'], 'a percent sign matches nothing, it is not "any characters"');
        $this->assertSame([], $this->suggest($rider, 'Kl_of')['groups'], 'an underscore is not "any one character"');
        $this->assertNotEmpty($this->suggest($rider, 'kloof')['groups']);
    }

    public function test_the_answer_is_private_and_not_stored_and_a_scope_the_person_does_not_hold_is_refused(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();

        $cache = (string) $this->actingAs($rider)->getJson(route('search.suggest', ['q' => 'kloof']))->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);

        foreach (['ops', 'fleet', 'business', 'driver', 'admin', "ops'--"] as $scope) {
            $this->suggest($rider, 'kloof', $scope, 403);
            $this->actingAs($rider)->get(route('search', ['q' => 'kloof', 'scope' => $scope]))->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- rider and driver

    public function test_a_rider_finds_only_their_own_trips_and_places(): void
    {
        $rider = User::factory()->withPersonalTeam()->create(['name' => 'Rita Rider']);
        $other = User::factory()->withPersonalTeam()->create(['name' => 'Olga Other']);
        $mine = $this->makeRide(['pickup_address' => '10 Kloof Street, Cape Town', 'dropoff_address' => 'Cape Town Station'], $rider);
        $this->makeRide(['pickup_address' => '99 Kloof Nek Road', 'dropoff_address' => 'Hout Bay'], $other);
        SavedPlace::create(['user_id' => $rider->id, 'kind' => 'home', 'label' => 'Home on Kloof', 'address' => '5 Kloof Avenue', 'lat' => -33.9, 'lng' => 18.4]);
        SavedPlace::create(['user_id' => $other->id, 'kind' => 'home', 'label' => 'Other Kloof home', 'address' => '7 Kloof Lane', 'lat' => -33.9, 'lng' => 18.4]);

        $json = $this->suggest($rider, 'kloof');
        $texts = implode(' | ', $this->texts($json));

        $this->assertStringContainsString('10 Kloof Street', $texts);
        $this->assertStringContainsString('#'.$mine->id, $texts);
        $this->assertStringContainsString('Home on Kloof', $texts);
        $this->assertStringNotContainsString('99 Kloof Nek', $texts, 'another rider\'s trip');
        $this->assertStringNotContainsString('Other Kloof home', $texts, 'another person\'s saved place');
        $this->assertSame(route('ride.trip', $mine), $this->group($json, 'rides')['rows'][0]['href']);
        $this->assertSame('all', end($json['groups'])['key'], 'the last row opens every result');
        $this->assertTrue(end($json['groups'])['rows'][0]['action']);
    }

    public function test_a_ride_number_finds_only_a_trip_the_rider_took(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $mine = $this->makeRide([], $rider);
        $theirs = $this->makeRide();

        $this->assertNotEmpty($this->group($this->suggest($rider, '#'.$mine->id), 'rides')['rows']);
        $this->assertNull($this->group($this->suggest($rider, '#'.$theirs->id), 'rides'));
    }

    public function test_a_driver_finds_their_own_trips_and_never_the_rider(): void
    {
        $driver = $this->makeApprovedDriver();
        $otherDriver = $this->makeApprovedDriver();
        $rider = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Passenger', 'email' => 'thandi-private@example.test']);
        $mine = $this->makeRide(['driver_id' => $driver->id, 'status' => 'completed', 'pickup_address' => '1 Long Street', 'dropoff_address' => '2 Wale Street'], $rider);
        $this->makeRide(['driver_id' => $otherDriver->id, 'status' => 'completed', 'pickup_address' => '3 Long Street'], $rider);

        $json = $this->suggest($driver, 'long street', 'driver');
        $texts = implode(' | ', $this->texts($json));

        $this->assertStringContainsString('1 Long Street', $texts);
        $this->assertStringNotContainsString('3 Long Street', $texts);
        $this->assertStringNotContainsString('Thandi', json_encode($json));
        $this->assertStringNotContainsString('thandi-private', json_encode($json));
        $this->assertSame(route('drive.trips.show', $mine), $this->group($json, 'trips')['rows'][0]['href']);
        $this->assertSame([], $this->suggest($driver, 'thandi', 'driver')['groups'], 'the rider\'s name is not searchable by the driver');
    }

    public function test_the_results_page_lists_the_same_rows_and_says_when_nothing_matches(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $this->makeRide(['pickup_address' => '10 Kloof Street'], $rider);

        $this->actingAs($rider)->get(route('search', ['q' => 'kloof']))->assertOk()->assertSee('10 Kloof Street')->assertSee('Your trips');
        $this->actingAs($rider)->get(route('search', ['q' => 'zzzzqq']))->assertOk()->assertSee('Nothing matches');
        $this->actingAs($rider)->get(route('search', ['q' => 'k']))->assertOk()->assertSee('at least two characters');
        $this->actingAs($rider)->get(route('search'))->assertOk()->assertSee('Type what you are looking for');
        $this->actingAs($rider)->get(route('search', ['q' => '<script>alert(1)</script>']))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    // ---------------------------------------------------------------- operations

    public function test_an_operator_finds_rides_drivers_vehicles_and_fleets_and_no_contact_details(): void
    {
        $admin = $this->operatorWith(['admin']);
        $driver = $this->makeApprovedDriver();
        $driver->forceFill(['name' => 'Sipho Searchable', 'email' => 'sipho-private@example.test'])->save();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_profile_id', $profile->id)->firstOrFail();
        $vehicle->forceFill(['plate_number' => 'CA 777 888', 'make' => 'Hyundai', 'model' => 'Grand'])->save();
        $ride = $this->makeRide(['pickup_address' => 'Braamfontein Search Pickup', 'driver_id' => $driver->id]);
        $fleet = $this->fleetOwnedBy(null, 'approved', 'Searchable Cabs');

        $rides = $this->suggest($admin, 'braamfontein search', 'ops');
        $this->assertSame(route('ops.rides.show', $ride->id), $this->group($rides, 'rides')['rows'][0]['href']);
        $this->assertNotNull($this->group($this->suggest($admin, '#'.$ride->id, 'ops'), 'rides'));

        $byName = $this->suggest($admin, 'sipho searchable', 'ops');
        $this->assertSame(route('ops.drivers.show', $driver->id), $this->group($byName, 'drivers')['rows'][0]['href']);

        $byEmail = $this->suggest($admin, 'sipho-private', 'ops');
        $this->assertNotNull($this->group($byEmail, 'drivers'), 'an operator can still find a driver by e-mail');
        $printed = collect($byEmail['groups'])->reject(fn ($g) => $g['key'] === 'all')->flatMap(fn ($g) => $g['rows'])->toJson();
        $this->assertStringNotContainsString('sipho-private', $printed, 'but the e-mail address is never printed in a row');
        $this->assertStringNotContainsString('@', $printed);

        $byPlate = $this->suggest($admin, 'ca 777', 'ops');
        $this->assertSame('CA 777 888', $this->group($byPlate, 'vehicles')['rows'][0]['code']);
        $this->assertSame(route('ops.fleets'), $this->group($this->suggest($admin, 'searchable cabs', 'ops'), 'fleets')['rows'][0]['href']);
        $this->assertNotNull($fleet);
    }

    public function test_an_operator_role_without_the_permission_for_a_route_does_not_see_that_group(): void
    {
        $this->makeRide(['pickup_address' => 'Roleless Pickup at Rosebank']);
        $driver = $this->makeApprovedDriver();
        $driver->forceFill(['name' => 'Roleless Driver'])->save();
        $this->fleetOwnedBy(null, 'approved', 'Roleless Cabs');

        // A pricing manager holds neither the ride desk, nor drivers, nor fleet review: nothing at all.
        $pricing = $this->operatorWith(['pricing_manager']);
        $this->assertFalse(app(OpsAccess::class)->allowsRoute($pricing, 'ops.rides'), 'precondition');
        $this->assertSame([], $this->suggest($pricing, 'roleless', 'ops')['groups']);

        // A verification reviewer holds drivers and fleet review, not the ride desk.
        $reviewer = $this->operatorWith(['verification_reviewer']);
        $json = $this->suggest($reviewer, 'roleless', 'ops');
        $this->assertNull($this->group($json, 'rides'), 'the ride desk is not this role\'s');
        $this->assertNotNull($this->group($json, 'drivers'));
        $this->assertNotNull($this->group($json, 'fleets'));
    }

    public function test_a_platform_user_who_is_not_an_operator_cannot_search_operations_even_by_naming_it(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['pickup_address' => 'Operator Only Pickup']);

        $this->suggest($driver, 'operator only', 'ops', 403);
        $this->assertSame([], $this->suggest($driver, 'operator only')['groups'], 'and an unscoped search of their own surface finds nothing of another person\'s');
    }

    // ---------------------------------------------------------------- fleet

    public function test_a_fleet_owner_finds_only_their_own_fleets_vehicles_and_drivers(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = $this->fleetOwnedBy($owner, 'approved', 'Own Cabs');
        $rival = $this->fleetOwnedBy(null, 'approved', 'Rival Cabs');
        $ownVehicle = $this->fleetVehicle($fleet, 'CA 555 111');
        $this->fleetVehicle($rival, 'CA 555 222');
        $this->fleetDriver($fleet, 'Naledi Ownfleet');
        $this->fleetDriver($rival, 'Naledi Rival');

        $vehicles = $this->suggest($owner, 'ca 555', 'fleet');
        $this->assertSame([route('fleet.vehicles.show', $ownVehicle)], array_column($this->group($vehicles, 'vehicles')['rows'], 'href'));
        $this->assertStringNotContainsString('CA 555 222', json_encode($vehicles));

        $drivers = $this->suggest($owner, 'naledi', 'fleet');
        $names = array_column($this->group($drivers, 'drivers')['rows'], 'label');
        $this->assertSame(['Naledi Ownfleet'], $names);
    }

    public function test_someone_with_no_fleet_cannot_search_a_fleet_and_a_fleet_member_is_limited_to_their_area(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->suggest($stranger, 'ca', 'fleet', 403);

        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = $this->fleetOwnedBy($owner, 'approved', 'Scoped Cabs');
        $this->fleetVehicle($fleet, 'CA 321 654');
        $this->fleetDriver($fleet, 'Zodwa Scoped');
        $manager = User::factory()->withPersonalTeam()->create();
        FleetMember::create(['fleet_id' => $fleet->id, 'user_id' => $manager->id, 'role' => 'manager', 'scopes' => ['drivers'], 'status' => 'active']);

        $json = $this->suggest($manager, 'zodwa', 'fleet');
        $this->assertNotNull($this->group($json, 'drivers'));
        $this->assertNull($this->group($this->suggest($manager, 'ca 321', 'fleet'), 'vehicles'), 'a manager without the vehicles area does not see vehicles');
    }
}
