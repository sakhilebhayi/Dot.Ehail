<?php

namespace Tests\Feature\Polish;

use App\Domain\Business\BusinessClock;
use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Inbox;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Ops\Insights\InsightsQuery;
use App\Domain\Ops\Live\IncidentPresenter;
use App\Domain\Ops\Support\TimelineWords;
use App\Domain\Rides\Models\RideEvent;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\TrustSignals;
use App\Http\Controllers\Driver\Support\Local as DriverLocal;
use App\Livewire\NotificationBell;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Ui\LocalTime;
use App\Support\Ui\PersonNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Concerns\ApprovesDriverDocuments;
use Tests\Concerns\FleetFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * The data and label defects the design jury found (R-46 polish, D-1906 to D-1914): one South African clock on every
 * page, unread counts that count things not messages, names instead of ids, statuses that agree with the documents,
 * counts that agree with the list beside them, newest first, and definitions in plain words.
 */
class DataLabelsTest extends TestCase
{
    use ApprovesDriverDocuments, FleetFixtures, OpsFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        PersonNames::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ time (D-1906)

    public function test_times_are_shown_in_south_african_time_whatever_the_server_clock_is(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'times are stored in UTC');
        $this->assertSame('Africa/Johannesburg', LocalTime::timezone());
        $this->assertSame('SAST', LocalTime::label());

        $utc = Carbon::parse('2026-11-10 05:30:00', 'UTC');
        $this->assertSame('07:30', LocalTime::time($utc));
        $this->assertSame('10 Nov 2026, 07:30', LocalTime::format($utc));
        $this->assertSame('07:30', LocalTime::time('2026-11-10T05:30:00Z'));
        $this->assertSame('07:30', LocalTime::time('2026-11-10 05:30:00'), 'a bare stored string is UTC');
        // Late evening UTC is already the next day in Johannesburg.
        $this->assertSame('11 Nov 2026, 00:15', LocalTime::format(Carbon::parse('2026-11-10 22:15:00', 'UTC')));
        $this->assertNull(LocalTime::format(null));
        $this->assertSame('Africa/Johannesburg', LocalTime::timezone('Not/AZone'), 'an unknown zone falls back, never to UTC');
    }

    public function test_the_trips_list_and_the_trip_page_show_the_same_south_african_time(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $at = Carbon::parse('2026-11-10 05:30:00', 'UTC');
        $ride = $this->makeRide(['status' => 'completed', 'requested_at' => $at->copy()->subMinutes(30), 'completed_at' => $at, 'final_fare_minor' => 6400, 'currency' => 'ZAR'], $passenger);
        $booked = $this->makeRide(['status' => 'scheduled', 'scheduled_for' => Carbon::parse('2026-11-12 05:30:00', 'UTC'), 'quoted_total_minor' => 6400, 'currency' => 'ZAR'], $passenger);
        Carbon::setTestNow('2026-11-10 06:00:00');

        $list = $this->actingAs($passenger)->get(route('ride.trips'))->assertOk()->getContent();
        $this->assertStringContainsString('10 Nov 2026, 07:30', $list);
        $this->assertStringContainsString('Thu 12 Nov, 07:30', $list);
        $this->assertStringNotContainsString('05:30', $list);

        $detail = $this->actingAs($passenger)->get(route('ride.trip', $booked))->assertOk()->getContent();
        $this->assertStringContainsString('Thu 12 Nov, 07:30', $detail);
        // What a person reads (the page also carries the UTC instant as data for the browser, which formats it in the same zone).
        $this->assertStringNotContainsString('05:30', $this->visibleText($detail));
        // The browser formats in the same zone: the page tells it which.
        $this->assertStringContainsString('<meta name="eh-tz" content="Africa/Johannesburg">', $detail);

        $this->assertStringNotContainsString('05:30', $this->visibleText((string) $this->actingAs($passenger)->get(route('ride.trip', $ride))->assertOk()->getContent()));
    }

    private function visibleText(string $html): string
    {
        return html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#si', '', $html)));
    }

    // ------------------------------------------------------------------ past trips (D-1908)

    public function test_past_trips_are_listed_newest_first_by_when_they_happened(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        // Created in an order that is not the order they happened in.
        $this->makeRide(['status' => 'completed', 'pickup_address' => 'Middle Street', 'requested_at' => now()->subDays(2), 'completed_at' => now()->subDays(2)], $passenger);
        $this->makeRide(['status' => 'completed', 'pickup_address' => 'Newest Street', 'requested_at' => now()->subHour(), 'completed_at' => now()->subMinutes(30)], $passenger);
        $this->makeRide(['status' => 'cancelled_by_passenger', 'pickup_address' => 'Oldest Street', 'requested_at' => now()->subDays(9)], $passenger);

        $this->actingAs($passenger)->get(route('ride.trips'))->assertOk()
            ->assertSeeInOrder(['Past trips', 'Newest Street', 'Middle Street', 'Oldest Street']);
    }

    // ------------------------------------------------------------------ unread count (D-1909)

    private function note(User $user, string $key, ?string $collapse, string $type = 'ride_update'): void
    {
        app(NotificationRouter::class)->send(new CommsMessage($user, $type, $key, 'Update', 'Body.', '/ride/trips', $collapse));
    }

    public function test_the_unread_count_counts_rides_once_and_the_badge_stops_at_nine_plus(): void
    {
        $this->assertNull(Inbox::badge(0));
        $this->assertSame('1', Inbox::badge(1));
        $this->assertSame('9', Inbox::badge(9));
        $this->assertSame('9+', Inbox::badge(10));
        $this->assertSame('9+', Inbox::badge(4000));

        $passenger = User::factory()->withPersonalTeam()->create();
        $this->assertSame(0, Inbox::countFor($passenger));

        // Two rides, six progress notifications each: two things to look at, not twelve.
        $rides = [$this->makeRide(['status' => 'completed'], $passenger), $this->makeRide(['status' => 'completed'], $passenger)];
        foreach ($rides as $ride) {
            foreach (['searching', 'assigned', 'arriving', 'arrived', 'started', 'completed'] as $step) {
                $this->note($passenger, 'ride:'.$ride->id.':'.$step, 'ride-'.$ride->id);
            }
        }
        $this->assertSame(12, $passenger->unreadNotifications()->count());
        $this->assertSame(2, Inbox::countFor($passenger));

        // Things that are not ride progress count one each.
        $this->note($passenger, 'payout:1:paid', null, 'payout_paid');
        $this->note($passenger, 'payout:2:paid', null, 'payout_paid');
        $this->assertSame(4, Inbox::countFor($passenger));
        $this->assertSame(4, app(Inbox::class)->unreadCount($passenger));

        Livewire::actingAs($passenger)->test(NotificationBell::class)->assertSet('unreadCount', 4);

        // Opening a ride's page is seeing its progress.
        $this->actingAs($passenger)->get(route('ride.trip', $rides[0]))->assertOk();
        $this->assertSame(3, Inbox::countFor($passenger));
        $this->assertSame(8, $passenger->unreadNotifications()->count(), 'only that ride was marked seen: the other ride\'s six and the two payouts stay unread');

        // Somebody else's ride marks nothing.
        $other = User::factory()->withPersonalTeam()->create();
        $this->actingAs($other)->get(route('ride.trip', $rides[1]))->assertNotFound();
        $this->assertSame(3, Inbox::countFor($passenger));
    }

    public function test_the_bell_shows_nine_plus_and_says_the_real_number_to_a_screen_reader(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        for ($i = 1; $i <= 12; $i++) {
            $this->note($user, 'payout:'.$i.':paid', null, 'payout_paid');
        }

        $this->actingAs($user);
        $html = Blade::render('<x-comms::bell surface="passenger" />');

        $this->assertStringContainsString('>9+</span>', $html);
        $this->assertStringContainsString('Notifications, 12 unread', $html);
        $this->assertStringNotContainsString('>12</span>', $html);
    }

    // ------------------------------------------------------------------ rating band (D-1907)

    public function test_a_driver_is_new_only_until_enough_ratings_are_counted(): void
    {
        $min = (int) config('ehail_safety.ratings.min_count_for_band', 5);

        $this->assertSame(['band' => 'new', 'label' => 'New on Dot.Ehail', 'count' => 0], TrustSignals::band(null, 0));
        $this->assertSame('new', TrustSignals::band(5.0, $min - 1)['band']);
        $this->assertSame(['band' => 'excellent', 'label' => 'Excellent', 'count' => $min], TrustSignals::band(4.9, $min));
        $this->assertSame('Very good', TrustSignals::band(4.6, 120)['label']);
        $this->assertSame('Good', TrustSignals::band(4.2, 120)['label']);
        $this->assertSame('Mixed reviews', TrustSignals::band(3.1, 120)['label']);
    }

    // ------------------------------------------------------------------ ops account badge (D-1910)

    public function test_an_approved_driver_is_not_plainly_approved_while_documents_are_outstanding(): void
    {
        $w = $this->seedOpsWorld();
        $admin = $this->operatorWith(['admin']);
        $profile = $w['profile'];
        $vehicle = Vehicle::query()->where('driver_profile_id', $profile->id)->firstOrFail();

        // Approved account, no documents on file.
        $this->actingAs($admin)->get(route('ops.drivers'))->assertOk()->assertSee('Approved, documents missing');
        $this->actingAs($admin)->get(route('ops.drivers.show', $w['driver']->id))->assertOk()->assertSee('Approved, documents missing');

        // Every required document approved and current: plainly approved.
        $this->approveAllDocuments($profile, $vehicle);
        $this->actingAs($admin)->get(route('ops.drivers'))->assertOk()
            ->assertDontSee('Approved, documents missing')->assertDontSee('Approved, documents waiting for review');
        $this->actingAs($admin)->get(route('ops.drivers.show', $w['driver']->id))->assertOk()
            ->assertDontSee('Approved, documents missing')->assertDontSee('Approved, documents waiting for review');
    }

    public function test_the_status_badge_words_for_each_document_state(): void
    {
        $render = fn (string $status, ?string $documents) => trim(strip_tags(Blade::render('@include("ops.partials.profile-status", ["status" => $status, "documents" => $documents])', compact('status', 'documents'))));

        $this->assertSame('Approved', $render('approved', null));
        $this->assertSame('Approved', $render('approved', 'verified'));
        $this->assertSame('Approved, documents waiting for review', $render('approved', 'pending_review'));
        $this->assertSame('Approved, documents missing', $render('approved', 'action_required'));
        $this->assertSame('Application pending', $render('pending', 'pending_review'));
        $this->assertSame('Suspended', $render('suspended', 'action_required'));
    }

    // ------------------------------------------------------------------ names, not ids (D-1913)

    public function test_timelines_name_people_instead_of_printing_their_ids(): void
    {
        $driver = User::factory()->withPersonalTeam()->create(['name' => 'Thabo Nkosi']);
        $ride = $this->makeRide();

        $sent = TimelineWords::describe(new RideEvent(['ride_id' => $ride->id, 'type' => 'offer_sent', 'actor_type' => 'system', 'context' => ['driver_id' => $driver->id]]));
        $this->assertSame('Offer sent to Thabo Nkosi', $sent['what']);

        $by = TimelineWords::describe(new RideEvent(['ride_id' => $ride->id, 'type' => 'transition', 'to_status' => 'driver_arrived', 'actor_type' => 'driver', 'actor_id' => $driver->id]));
        $this->assertSame('by driver Thabo Nkosi', $by['detail']);
        $this->assertStringNotContainsString((string) $driver->id, $by['detail']);

        // Somebody no longer on record is said so, with the id only as a reference.
        $this->assertSame('a deleted account (#999999)', PersonNames::of(999999));
        $this->assertNull(PersonNames::of(null));

        // A renamed person reads correctly on the next request (the memo is per request).
        $driver->forceFill(['name' => 'Thabo M. Nkosi'])->save();
        $this->app->instance('request', Request::create('/next'));
        $this->assertSame('Thabo M. Nkosi', PersonNames::of($driver->id));
    }

    public function test_the_operations_ride_and_safety_pages_show_names(): void
    {
        $w = $this->seedOpsWorld();
        $admin = $this->operatorWith(['admin']);
        RideEvent::create(['ride_id' => $w['completed']->id, 'sequence' => 900, 'type' => 'offer_sent', 'actor_type' => 'system', 'context' => ['driver_id' => $w['driver']->id], 'occurred_at' => now()]);
        RideEvent::create(['ride_id' => $w['completed']->id, 'sequence' => 901, 'type' => 'transition', 'from_status' => 'driver_assigned', 'to_status' => 'driver_arrived', 'actor_type' => 'driver', 'actor_id' => $w['driver']->id, 'occurred_at' => now()]);

        $this->actingAs($admin)->get(route('ops.rides.show', $w['completed']->id))->assertOk()
            ->assertSee('Offer sent to Sipho Driver')->assertSee('by driver Sipho Driver')
            ->assertDontSee('to driver '.$w['driver']->id)->assertDontSee('by driver '.$w['driver']->id.' ');

        $this->actingAs($admin)->get(route('ops.safety.show', $w['incident']->id))->assertOk()
            ->assertDontSee('by user '.$w['passenger']->id);
    }

    public function test_the_incident_page_names_who_acted_and_shows_south_african_times(): void
    {
        // 05:30 UTC is 07:30 in Johannesburg: the incident page and the live board's incident rail must say 07:30.
        Carbon::setTestNow('2026-11-10 05:30:00');
        $w = $this->seedOpsWorld();
        $admin = $this->operatorWith(['admin']);
        $admin->forceFill(['name' => 'Nomsa Dlamini'])->save();
        app(IncidentService::class)->acknowledge($w['incident']->fresh(), $admin);

        $html = (string) $this->actingAs($admin)->get(route('ops.safety.show', $w['incident']->id))->assertOk()->getContent();
        $visible = $this->visibleText($html);

        $this->assertStringContainsString('by Nomsa Dlamini', $visible);
        $this->assertStringContainsString('by Thandi Passenger', $visible);
        $this->assertDoesNotMatchRegularExpression('/by user \d+/', $visible);
        $this->assertStringContainsString('10 Nov 2026 07:30:00 SAST', $visible);
        $this->assertStringContainsString('10 Nov 07:30:00', $visible);
        $this->assertStringContainsString('times in SAST', $visible);
        $this->assertStringNotContainsString('05:30', $visible);

        $card = app(IncidentPresenter::class)->rail($w['incident']->fresh(['reporter', 'owner']));
        $this->assertStringStartsWith('Raised 07:30 SAST, ', $card['opened_text']);
    }

    public function test_business_and_driver_pages_use_the_same_display_zone(): void
    {
        $this->assertSame(LocalTime::timezone(), BusinessClock::timezone());
        $this->assertSame('07:30', DriverLocal::at('2026-11-10 05:30:00')->format('H:i'));
        $this->assertSame('07:30', BusinessClock::local(Carbon::parse('2026-11-10 05:30:00', 'UTC'))->format('H:i'));

        // A broken setting never leaks UTC or throws: the platform zone is used.
        config(['ehail_business.timezone' => 'Mars/Olympus', 'ehail_driver.timezone' => '']);
        $this->assertSame('Africa/Johannesburg', BusinessClock::timezone());
        $this->assertSame('07:30', DriverLocal::at('2026-11-10 05:30:00')->format('H:i'));
    }

    // ------------------------------------------------------------------ fleet "applying" (D-1911)

    public function test_the_fleet_strip_counts_every_application_not_just_the_five_listed(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::findOrFail($fleet->owner_user_id);
        $this->fleetDriver($fleet, 'Approved Driver', 'approved');
        for ($i = 1; $i <= 7; $i++) {
            $this->fleetDriver($fleet, 'Applicant '.$i, 'pending');
        }
        $this->assertSame(7, DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->where('status', 'pending')->count());

        $this->actingAs($owner)->get(route('fleet.home'))->assertOk()
            ->assertSee('7 applying')->assertDontSee('5 applying')->assertDontSee('0 applying');
    }

    // ------------------------------------------------------------------ insights wording (D-1914)

    public function test_insight_definitions_are_plain_words_with_no_table_or_column_names(): void
    {
        $leaks = ['_at', '_s ', '_s)', '_minor', '_id', 'rides.', 'ride_events', 'driver_availabilit', 'on_trip', 'pickup_eta', 'needs_review', 'cancelled_by', 'no_show', '`', '::'];

        foreach (InsightsQuery::DEFINITIONS as $key => $text) {
            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $text, $key.' definition leaks "'.$leak.'"');
            }
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, $key.' definition contains a snake_case name');
        }

        $this->seedOpsWorld();
        $html = $this->actingAs($this->operatorWith(['admin']))->get(route('ops.insights'))->assertOk()->getContent();
        $visible = $this->visibleText($html);

        foreach (['pickup_eta_s', 'completed_at', 'accepted_at', 'on_trip', 'final_fare_minor', 'rides.status', 'cancelled_by_passenger', 'straight_line_estimate'] as $column) {
            $this->assertStringNotContainsString($column, $visible, 'the insights page prints "'.$column.'"');
        }
    }
}
