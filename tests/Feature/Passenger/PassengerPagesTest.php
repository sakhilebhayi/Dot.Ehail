<?php

namespace Tests\Feature\Passenger;

use App\Domain\Passenger\Models\SavedPlace;
use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * The passenger app's pages and its own small JSON endpoints: who may see what (a passenger never sees another's
 * ride, receipt, case or incident), headers, honest states, saved places.
 */
class PassengerPagesTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private function passenger(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_guests_are_sent_to_sign_in_and_the_fare_calculator_is_public(): void
    {
        foreach (['/ride', '/ride/trips', '/ride/safety', '/ride/account', '/ride/help', '/ride/help/new'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->getJson(route('ride.api.places.index'))->assertUnauthorized();

        $page = $this->get(route('fares.calculator'))->assertOk()->assertSee('See the fare first')->assertSee('Sign in to ride')
            ->assertDontSee('id="pax-pickup"', false);
        $this->assertSame('camera=(), microphone=(), geolocation=(self), payment=(), usb=()', $page->headers->get('Permissions-Policy'));
        $this->assertOnlyOwnHostsAndTiles($page);
    }

    public function test_every_passenger_page_renders_with_the_passenger_policy_and_one_heading(): void
    {
        $user = $this->passenger();

        foreach ([route('ride.home'), route('ride.trips'), route('ride.safety'), route('ride.account'), route('ride.support'), route('ride.support.create')] as $url) {
            $page = $this->actingAs($user)->get($url)->assertOk();
            $csp = (string) $page->headers->get('Content-Security-Policy'); // enforced since R-46 security S-29
            $this->assertStringContainsString("default-src 'self'", $csp, $url);
            $this->assertStringNotContainsString('fonts.googleapis.com', $csp, $url);
            $this->assertStringNotContainsString('cdn.tailwindcss.com', $csp, $url);
            $this->assertSame(1, substr_count($page->getContent(), '<h1'), $url.' has one page heading');
            $this->assertOnlyOwnHostsAndTiles($page);
        }

        // Geolocation is allowed only on the pages that can use it.
        $this->assertStringContainsString('geolocation=(self)', $this->actingAs($user)->get(route('ride.home'))->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=()', $this->actingAs($user)->get(route('ride.trips'))->headers->get('Permissions-Policy'));
    }

    public function test_a_passenger_cannot_open_another_passengers_ride_or_its_overview(): void
    {
        $owner = $this->passenger();
        $ride = $this->makeRide(['status' => 'completed', 'completed_at' => now()], $owner);
        $stranger = $this->passenger();

        $this->actingAs($stranger)->get(route('ride.trip', $ride))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('ride.api.rides.overview', $ride))->assertNotFound();
        $this->actingAs($stranger)->get(route('ride.home', ['again' => $ride->id]))->assertOk()->assertSee('"again":null', false);

        $this->actingAs($owner)->get(route('ride.trip', $ride))->assertOk()->assertSee('You have arrived');
        $this->actingAs($owner)->getJson(route('ride.api.rides.overview', $ride))->assertOk()
            ->assertJsonPath('ride_id', $ride->id)->assertJsonPath('cancellation_now', null)->assertJsonPath('receipt', null);
    }

    public function test_ended_and_scheduled_rides_show_the_right_panels(): void
    {
        $user = $this->passenger();
        $expired = $this->makeRide(['status' => 'expired', 'expired_at' => now()], $user);
        $this->actingAs($user)->get(route('ride.trip', $expired))->assertOk()
            ->assertSee('No driver found')->assertSee('You are not charged.')->assertSee('Book this route again');

        $scheduled = $this->makeRide(['status' => 'scheduled', 'scheduled_for' => now()->addDay()], $user);
        $this->actingAs($user)->get(route('ride.trip', $scheduled))->assertOk()
            ->assertSee('Ride booked')->assertSee('Awaiting driver')->assertSee('Change the pickup time');
        $this->actingAs($user)->get(route('ride.trips'))->assertOk()->assertSee('Awaiting driver');
    }

    public function test_the_booking_page_opens_the_ride_in_progress(): void
    {
        $user = $this->passenger();
        $ride = $this->makeRide(['status' => 'searching'], $user);

        $this->actingAs($user)->get(route('ride.home'))->assertRedirect(route('ride.trip', $ride));
        $this->actingAs($user)->get(route('ride.home', ['new' => 1]))->assertOk()->assertSee('Where to?')
            // The payment chooser asks the business domain for profiles and its policy check (D-817).
            ->assertSee(json_encode(route('business.api.profiles')), false)->assertSee(json_encode(route('business.api.prepare')), false);
    }

    public function test_saved_places_one_home_one_work_and_a_limit_on_others(): void
    {
        $user = $this->passenger();
        $place = ['address' => '10 Kloof Street, Gardens', 'lat' => -33.94, 'lng' => 18.41];

        $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'home'] + $place)->assertCreated()->assertJsonPath('data.label', 'Home');
        $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'home', 'address' => 'New home'] + $place)->assertCreated();
        $this->assertSame(1, SavedPlace::where('user_id', $user->id)->where('kind', 'home')->count(), 'a new home replaces the old one');
        $this->assertSame('New home', SavedPlace::where('user_id', $user->id)->where('kind', 'home')->value('address'));

        for ($i = 0; $i < SavedPlace::MAX_OTHER; $i++) {
            $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'other', 'label' => "Place $i"] + $place)->assertCreated();
        }
        $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'other'] + $place)->assertStatus(422)->assertJsonPath('error', 'too_many_places');
        $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'castle'] + $place)->assertStatus(422);
        $this->actingAs($user)->postJson(route('ride.api.places.store'), ['kind' => 'work', 'address' => 'x', 'lat' => 99, 'lng' => 0])->assertStatus(422);

        $list = $this->actingAs($user)->getJson(route('ride.api.places.index'))->assertOk()->json('data');
        $this->assertSame('home', $list[0]['kind'], 'home first');

        // Someone else's place cannot be removed, and does not show in their list.
        $other = $this->passenger();
        $home = SavedPlace::where('user_id', $user->id)->where('kind', 'home')->first();
        $this->actingAs($other)->deleteJson(route('ride.api.places.destroy', $home))->assertNotFound();
        $this->assertSame([], $this->actingAs($other)->getJson(route('ride.api.places.index'))->json('data'));
        $this->actingAs($user)->deleteJson(route('ride.api.places.destroy', $home))->assertOk()->assertJsonPath('removed', true);
        $this->assertModelMissing($home);

        // The booking and account pages carry them.
        $this->actingAs($user)->get(route('ride.home'))->assertSee('Place 0');
        $this->actingAs($user)->get(route('ride.account'))->assertSee('Place 0');
    }

    public function test_pickup_etas_need_a_point_and_say_none_when_nobody_is_near(): void
    {
        $user = $this->passenger();
        $this->actingAs($user)->postJson(route('ride.api.pickup-etas'), ['lat' => 200, 'lng' => 0])->assertStatus(422);

        $etas = $this->actingAs($user)->postJson(route('ride.api.pickup-etas'), ['lat' => -33.94, 'lng' => 18.41])->assertOk()->json('categories');
        $this->assertNotEmpty($etas);
        foreach ($etas as $key => $eta) {
            $this->assertSame('none', $eta['state'], $key);
        }
    }

    public function test_incident_and_help_pages_belong_to_their_owner(): void
    {
        $owner = $this->passenger();
        $stranger = $this->passenger();
        $incident = app(IncidentService::class)->raise(null, $owner, IncidentKind::Harassment, ['description' => 'Followed after drop-off'])['incident'];

        $this->actingAs($stranger)->get(route('ride.safety.incident', $incident))->assertNotFound();
        $this->actingAs($owner)->get(route('ride.safety.incident', $incident))->assertOk()
            ->assertSee('What we did')->assertSee('What we did not do')->assertSee('tel:10111', false);

        $case = app(SupportCaseService::class)->open($owner, SupportCategory::AccountProblem, 'Cannot change email', 'Please help');
        $this->actingAs($stranger)->get(route('ride.support.show', $case))->assertNotFound();
        $this->actingAs($owner)->get(route('ride.support.show', $case))->assertOk()->assertSee('Case '.$case->reference);
    }

    public function test_new_help_case_preselects_one_of_the_passengers_own_trips(): void
    {
        $user = $this->passenger();
        $mine = $this->makeRide(['status' => 'completed', 'completed_at' => now()], $user);
        $theirs = $this->makeRide(['status' => 'completed', 'completed_at' => now()]);

        $page = $this->actingAs($user)->get(route('ride.support.create', ['ride' => $mine->id, 'category' => 'lost_property']))->assertOk();
        $page->assertSee('value="'.$mine->id.'" selected', false)->assertSee('value="lost_property" selected', false);
        $this->actingAs($user)->get(route('ride.support.create', ['ride' => $theirs->id]))->assertOk()->assertDontSee('value="'.$theirs->id.'"', false);
    }

    public function test_the_account_page_links_notifications_and_the_card_flow_returns_here(): void
    {
        $user = $this->passenger();
        $this->actingAs($user)->get(route('ride.account'))->assertOk()
            ->assertSee(route('ride.notifications'))->assertSee(route('ride.notifications.settings'))->assertSee(route('profile.show'));
        $this->assertSame('/ride/account', config('money.card_setup.return_to'));
    }

    public function test_the_safety_centre_lists_the_emergency_numbers_and_the_ride_going_now(): void
    {
        $user = $this->passenger();
        $this->actingAs($user)->get(route('ride.safety'))->assertOk()->assertSee('tel:10111', false)->assertSee('tel:10177', false)->assertSee('tel:112', false)
            ->assertDontSee('Your ride now')->assertSee('Dot.Ehail does not dispatch emergency services');

        $this->makeRide(['status' => 'searching'], $user);
        $this->actingAs($user)->get(route('ride.safety'))->assertOk()->assertSee('Your ride now')->assertSee('data-pax-sos-open', false);
    }

    public function test_the_signed_in_fare_calculator_offers_address_search(): void
    {
        $this->actingAs($this->passenger())->get(route('fares.calculator'))->assertOk()->assertSee('id="pax-pickup"', false)->assertSee('Book a ride');
    }

    private function assertOnlyOwnHostsAndTiles(TestResponse $page): void
    {
        // The header's "Dot Apps" switcher (shared dot-ui) is the one place that links to InfoDot, always through its /go;
        // it is taken out first and checked on its own (DotUiHeaderTest), so everything else still has to be own-host.
        $html = preg_replace('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', '', $page->getContent());
        preg_match_all('#(?:src|href)="(https?://[^"/]+)#', $html, $m);
        foreach (array_unique($m[1]) as $origin) {
            $this->assertSame(rtrim(config('app.url'), '/'), $origin, 'no third-party host is loaded');
        }
    }
}
