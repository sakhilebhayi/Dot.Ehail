<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Team;
use App\Models\User;
use App\Support\Ui\BookingReadiness;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;

/**
 * R-51 dashboard build: the desk and detail columns per role, the selected ride validated against the loaded rows,
 * every new query scoped to the person (tenant isolation), honest zeroes, the single primary action, and the rule that
 * a person with a trip in progress never makes /dashboard wait on InfoDot.
 */
class DashboardBuildTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, RefreshDatabase;

    private const ACME = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ehail-geo.routing.driver' => 'none', 'money.cash.enabled' => false, 'paystack.secret_key' => '']);
    }

    private function xpath($response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function openBookings(): void
    {
        config(['ehail-geo.routing.driver' => 'osrm', 'ehail-geo.providers.osrm.url' => 'https://osrm.example.test', 'money.cash.enabled' => true]);
        Artisan::call('ehail:geo-reference-areas');
        Artisan::call('ehail:pricing:install-example-tariff');
        $this->app->forgetInstance(BookingReadiness::class);
    }

    public function test_every_role_has_exactly_one_primary_action_and_one_h1(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->fleetOwnedBy($owner);
        $rider = User::factory()->withPersonalTeam()->create();

        foreach (['operator' => $operator, 'driver' => $driver, 'fleet' => $owner, 'rider' => $rider] as $role => $user) {
            $xpath = $this->xpath($this->actingAs($user)->get(route('dashboard'))->assertOk());

            $this->assertSame($role, $xpath->query('//*[@data-landing-role]')->item(0)->getAttribute('data-landing-role'));
            $this->assertSame(1, $xpath->query('//*[@data-primary-action]')->length, $role.' has one primary action');
            $this->assertSame(1, $xpath->query('//main//h1')->length, $role.' has one h1');
            $this->assertSame('Today', trim($xpath->query('//header[contains(@class, "topbar")]//*[contains(@class, "topbar-title")]')->item(0)->textContent));
        }
    }

    public function test_the_selected_ride_is_one_of_the_loaded_rows_and_an_arbitrary_id_falls_back_to_the_first(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $first = $this->makeRide(['pickup_address' => 'Older pickup', 'dropoff_address' => 'Older stop', 'status' => 'completed', 'final_fare' => 50], $rider);
        $second = $this->makeRide(['pickup_address' => 'Newer pickup', 'dropoff_address' => 'Newer stop', 'status' => 'completed', 'final_fare' => 60], $rider);
        $strangers = $this->makeRide(['pickup_address' => 'Stranger pickup', 'dropoff_address' => 'Stranger stop', 'status' => 'completed']);

        $panel = fn ($x) => $x->query('//*[@data-landing-latest]')->item(0)->textContent;

        $chosen = $this->xpath($this->actingAs($rider)->get(route('dashboard', ['ride' => $first->id]))->assertOk());
        $this->assertStringContainsString('Older pickup', $panel($chosen));
        $this->assertSame(1, $chosen->query('//tr[@aria-selected="true"]')->length);

        // Another person's ride id is not in the loaded rows: nothing of it is shown, the first row is selected.
        $other = $this->xpath($this->actingAs($rider)->get(route('dashboard', ['ride' => $strangers->id]))->assertOk());
        $this->assertStringNotContainsString('Stranger', $other->query('//main')->item(0)->textContent);
        $this->assertSame(1, $other->query('//tr[@aria-selected="true"]')->length);

        // Garbage values fall back too, and never error.
        foreach (['abc', '0', '-5', '99999999', '1; drop table rides'] as $value) {
            $this->actingAs($rider)->get(route('dashboard', ['ride' => $value]))->assertOk();
        }
        $this->assertNotNull($second);
    }

    public function test_an_operator_with_bookings_open_sees_the_decision_counts_and_the_calm_sentence(): void
    {
        $this->openBookings();
        $operator = $this->makeOperator();

        $xpath = $this->xpath($this->actingAs($operator)->get(route('dashboard'))->assertOk());
        $this->assertSame(0, $xpath->query('//*[@data-landing-setup]')->length, 'no setup line once bookings are open');
        $this->assertSame(1, $xpath->query('//*[@data-landing-decisions]')->length);
        $this->assertStringContainsString('Nothing needs a decision right now.', $xpath->query('//*[@data-landing-decisions]')->item(0)->textContent);

        $this->makeRide(['status' => 'searching']);
        $this->makeRide(['status' => 'requested']);
        \Illuminate\Support\Facades\Cache::flush();

        $xpath = $this->xpath($this->actingAs($operator)->get(route('dashboard'))->assertOk());
        $rows = [];
        foreach ($xpath->query('//*[@data-landing-decisions]//li') as $li) {
            $rows[] = preg_replace('/\s+/', ' ', trim($li->textContent));
        }
        $this->assertContains('Waiting for a driver 2', $rows);
        $this->assertContains('Rides to review 0', $rows);
    }

    public function test_the_decision_block_is_absent_for_a_rider_and_while_bookings_are_closed(): void
    {
        $this->assertSame(0, $this->xpath($this->actingAs($this->makeOperator())->get(route('dashboard'))->assertOk())->query('//*[@data-landing-decisions]')->length);

        $this->openBookings();
        $this->assertSame(0, $this->xpath($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard'))->assertOk())->query('//*[@data-landing-decisions]')->length);
    }

    public function test_the_fleet_digest_counts_only_the_owners_own_fleet(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = $this->fleetOwnedBy($owner, name: 'Own Cabs');
        $this->fleetVehicle($fleet, 'CA 1');
        $this->fleetVehicle($fleet, 'CA 2');
        $this->fleetDriver($fleet, 'Applicant One', 'pending');

        $stranger = $this->fleetOwnedBy(null, name: 'Other Cabs');
        $this->fleetVehicle($stranger, 'ZZ 1');
        $this->fleetVehicle($stranger, 'ZZ 2');
        $this->fleetVehicle($stranger, 'ZZ 3');
        $this->fleetDriver($stranger, 'Applicant Two', 'pending');
        $this->fleetDriver($stranger, 'Applicant Three', 'pending');

        $xpath = $this->xpath($this->actingAs($owner)->get(route('dashboard'))->assertOk());
        $digest = $xpath->query('//*[@data-landing-fleet-digest]')->item(0);
        $this->assertNotNull($digest);
        $this->assertStringContainsString('Own Cabs today', $digest->textContent);
        $this->assertStringNotContainsString('Other Cabs', $xpath->query('//main')->item(0)->textContent);

        $counts = [];
        foreach ($xpath->query('.//li', $digest) as $li) {
            $counts[trim($li->getElementsByTagName('span')->item(0)->textContent)] = (int) $li->getElementsByTagName('span')->item(1)->textContent;
        }
        $this->assertSame(2, $counts['Vehicles in service']);
        $this->assertSame(1, $counts['Driver applications waiting']);
        $this->assertSame(0, $counts['Drivers online now']);
        $this->assertSame(0, $xpath->query('//*[@data-landing-ledger]')->length, 'a fleet owner has no ride ledger');
    }

    public function test_the_driver_sees_today_and_only_a_riders_first_name(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['status' => 'completed', 'final_fare' => 90, 'driver_id' => $driver->id], User::factory()->withPersonalTeam()->create(['name' => 'Zanele Mthembu']));

        $xpath = $this->xpath($this->actingAs($driver)->get(route('dashboard'))->assertOk());
        $this->assertSame(1, $xpath->query('//*[@data-landing-driver-today]')->length);
        $this->assertStringContainsString('No trips yet today.', $xpath->query('//*[@data-landing-driver-today]')->item(0)->textContent);
        $main = $xpath->query('//main')->item(0)->textContent;
        $this->assertStringContainsString('Zanele', $main);
        $this->assertStringNotContainsString('Mthembu', $main, 'a driver sees the first name only (S-08)');
        $this->assertSame(1, $xpath->query('//*[@data-landing-driver-today]//dd[contains(@class, "eh-dash__big")]')->length);
    }

    public function test_an_operator_and_a_fleet_owner_ledger_hold_only_rides_they_may_see(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $mine = $this->makeRide(['pickup_address' => 'My own pickup', 'status' => 'completed'], $rider);
        $this->makeRide(['pickup_address' => 'Somebody elses pickup', 'status' => 'completed']);

        $xpath = $this->xpath($this->actingAs($rider)->get(route('dashboard'))->assertOk());
        $this->assertSame(1, $xpath->query('//*[@data-landing-ledger]//tbody/tr')->length);
        $this->assertStringNotContainsString('Somebody elses', $xpath->query('//main')->item(0)->textContent);
        $this->assertNotNull($mine);

        $operator = $this->makeOperator();
        $this->assertSame(2, $this->xpath($this->actingAs($operator)->get(route('dashboard'))->assertOk())->query('//*[@data-landing-ledger]//tbody/tr')->length);
    }

    public function test_a_rider_mid_ride_sees_the_strip_and_the_dashboard_never_asks_infodot(): void
    {
        config([
            'ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'ehail', 'secret' => 's', 'timeout' => 2, 'provision' => true, 'entitlement_check' => false, 'switcher_timeout' => 1],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://infodot.test/*' => Http::response(['platform' => 'ehail', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => []])]);

        $rider = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $rider->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => self::ACME]);
        $rider->switchTeam($team);
        $rider = $rider->fresh();

        // No trip: an ordinary page, so the switcher asks InfoDot (once, then cached).
        $this->actingAs($rider)->get(route('dashboard'))->assertOk();
        Http::assertSentCount(1);

        // A trip in progress: the page is treated as live, cache only, no new request ever.
        \Illuminate\Support\Facades\Cache::flush();
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id], $rider);

        $xpath = $this->xpath($this->actingAs($rider)->get(route('dashboard'))->assertOk());
        $this->assertSame(1, $xpath->query('//*[@data-landing-trip]')->length, 'the trip strip is the first element of the desk');
        Http::assertSentCount(1);

        // And the primary action is still the one thing that opens the live ride.
        $this->assertSame(1, $xpath->query('//*[@data-primary-action]')->length);
        $this->assertStringContainsString('Open your current ride', $xpath->query('//*[@data-primary-action]')->item(0)->textContent);
    }

    public function test_no_trip_strip_without_an_active_trip_and_a_driver_on_a_trip_gets_it_too(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $this->assertSame(0, $this->xpath($this->actingAs($rider)->get(route('dashboard'))->assertOk())->query('//*[@data-landing-trip]')->length);

        $driver = $this->makeApprovedDriver();
        $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id]);
        $xpath = $this->xpath($this->actingAs($driver)->get(route('dashboard'))->assertOk());
        $this->assertSame(1, $xpath->query('//*[@data-landing-trip]')->length);
        $this->assertStringContainsString('Open your current trip', $xpath->query('//*[@data-primary-action]')->item(0)->textContent);
    }

    public function test_the_route_line_is_decorative_and_every_animation_is_gated_for_reduced_motion(): void
    {
        $xpath = $this->xpath($this->actingAs($this->makeOperator())->get(route('dashboard'))->assertOk());
        $route = $xpath->query('//*[contains(@class, "eh-dash__route")][@aria-hidden="true"]');
        $this->assertSame(1, $route->length);
        // five stops while bookings are closed: the real setup steps
        $this->assertSame(5, $xpath->query('//*[contains(@class, "eh-dash__route-stop")]')->length);

        $css = (string) file_get_contents(resource_path('css/ds/home.css'));
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertDoesNotMatchRegularExpression('/transition:\s*all/', $css);
        // animations are only declared inside the no-preference block
        $outside = preg_replace('/@media \(prefers-reduced-motion: no-preference\) \{.*?\n\}\n/s', '', $css);
        $this->assertSame(1, substr_count((string) $outside, 'animation: none'), 'only the reduced-motion reset names an animation outside the gated block');
    }

    public function test_a_driver_who_is_not_approved_yet_gets_the_setup_headline_and_no_earnings_block_claims(): void
    {
        $profile = DriverProfile::factory()->for(User::factory()->withPersonalTeam()->create())->create(['status' => 'pending']);
        $xpath = $this->xpath($this->actingAs($profile->user)->get(route('dashboard'))->assertOk());

        $this->assertStringContainsString('A few steps before your first trip', $xpath->query('//main//h1')->item(0)->textContent);
        $this->assertStringContainsString('Not approved yet', $xpath->query('//*[@data-landing-driver-today]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-primary-action]')->length);
    }
}
