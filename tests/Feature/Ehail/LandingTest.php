<?php

namespace Tests\Feature\Ehail;

use App\Models\Fleet;
use App\Models\Team;
use App\Models\User;
use App\Support\Ui\BookingReadiness;
use App\Support\Ui\MoneyFormatter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * The signed-in landing (/dashboard, R-50): one dominant action per role that leads to a page that answers, the
 * original logo file, a welcome from real data, an honest answer when nobody can book, and no invented figure.
 */
class LandingTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    /** SHA-256 of the logo files as they stood before this landing: the artwork is never altered. */
    private const LOGO_HASHES = [
        'images/logo.png' => '499a8e5050decb682b736392fe8c13304d9ef38d403c020b0ca42a92e8faa52e',
        'images/logo-light.png' => 'c224468195ae9a0b9f498509ef196a49b8cf4f2f0695ff0399bc57b40a4e835d',
    ];

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** Routing, a way to pay, the reference areas and the example tariff: the state in which a passenger can book. */
    private function openBookings(): void
    {
        config([
            'ehail-geo.routing.driver' => 'osrm',
            'ehail-geo.providers.osrm.url' => 'https://osrm.example.test',
            'money.cash.enabled' => true,
        ]);
        Artisan::call('ehail:geo-reference-areas');
        Artisan::call('ehail:pricing:install-example-tariff');
        $this->app->forgetInstance(BookingReadiness::class);
    }

    private function inCompany(User $user, string $company): User
    {
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $company, 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    /** The one primary action on the landing: asserts there is exactly one and returns its link. */
    private function primaryAction(DOMXPath $xpath): string
    {
        $actions = $xpath->query('//*[@data-primary-action]');
        $this->assertSame(1, $actions->length, 'exactly one primary action');
        $this->assertSame('a', $actions->item(0)->nodeName);

        return $actions->item(0)->getAttribute('href');
    }

    public function test_the_readiness_check_names_what_is_missing_and_opens_when_everything_is_in_place(): void
    {
        $missing = array_column((new BookingReadiness)->missing(), 'key');
        $this->assertSame(['routing', 'payment', 'area', 'tariff'], $missing);
        $this->assertFalse((new BookingReadiness)->isOpen());

        $this->openBookings();

        $this->assertSame([], (new BookingReadiness)->missing());
        $this->assertTrue((new BookingReadiness)->isOpen());
    }

    public function test_a_rider_books_a_ride_when_bookings_are_open(): void
    {
        $this->openBookings();
        $rider = $this->inCompany(User::factory()->withPersonalTeam()->create(['name' => 'Ayanda Khumalo']), 'Kloof Street Studio');

        $response = $this->actingAs($rider)->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(route('ride.home'), $this->primaryAction($xpath));
        $this->assertStringContainsString('Book a ride', $xpath->query('//*[@data-primary-action]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-landing-notice]')->length);
        $welcome = preg_replace('/\s+/', ' ', $xpath->query('//*[@data-landing-welcome]')->item(0)->textContent);
        $this->assertStringContainsString('Welcome, Ayanda.', $welcome);
        $this->assertStringContainsString('Kloof Street Studio', $welcome);

        $this->actingAs($rider)->get(route('ride.home'))->assertOk();
    }

    public function test_a_rider_is_told_bookings_are_closed_and_is_not_offered_a_booking(): void
    {
        $rider = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Dube']);

        $response = $this->actingAs($rider)->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response);
        $main = '//main[@id="main-content"]';

        $response->assertSee('Bookings are not open yet');
        $notice = $xpath->query('//*[@data-landing-notice]')->item(0)->textContent;
        $this->assertStringContainsString('You cannot book a ride yet', $notice);
        $this->assertStringContainsString('published prices', $notice);
        // No setting names for a rider, and no link from the landing into a booking that would be refused.
        $this->assertStringNotContainsString('EHAIL_', $xpath->query($main)->item(0)->textContent);
        $this->assertSame(0, $xpath->query($main.'//a[@href="'.route('ride.home').'" or @href="'.route('rides.create').'"][@data-primary-action]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-landing-setup]')->length);

        $target = $this->primaryAction($xpath);
        $this->assertSame(route('ride.safety'), $target);
        $this->actingAs($rider)->get($target)->assertOk();

        // A personal team is the person, not a company: no company is greeted.
        $this->assertStringNotContainsString('signed in for', $xpath->query('//*[@data-landing-welcome]')->item(0)->textContent);
    }

    public function test_a_rider_with_a_ride_in_progress_is_taken_to_it(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'requested'], $rider);

        $target = $this->primaryAction($this->xpath($this->actingAs($rider)->get(route('dashboard'))->assertOk()));

        $this->assertSame(route('ride.trip', $ride), $target);
        $this->actingAs($rider)->get($target)->assertOk();
    }

    public function test_a_rider_with_past_rides_and_closed_bookings_is_sent_to_their_trips(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $this->makeRide(['status' => 'completed', 'final_fare' => 85.50], $rider);

        $target = $this->primaryAction($this->xpath($this->actingAs($rider)->get(route('dashboard'))->assertOk()));

        $this->assertSame(route('ride.trips'), $target);
        $this->actingAs($rider)->get($target)->assertOk();
    }

    public function test_a_driver_goes_to_the_console_and_keeps_the_rider_space_as_a_secondary_link(): void
    {
        $driver = $this->makeApprovedDriver(online: false);

        $xpath = $this->xpath($this->actingAs($driver)->get(route('dashboard'))->assertOk());
        $target = $this->primaryAction($xpath);

        $this->assertSame(route('drive.home'), $target);
        $this->assertStringContainsString('Go online', $xpath->query('//*[@data-primary-action]')->item(0)->textContent);
        $this->assertStringContainsString('no offers will arrive', $xpath->query('//*[@data-landing-notice]')->item(0)->textContent);
        $this->assertSame(route('ride.home'), $xpath->query('//nav[@aria-label="Your Dot.Ehail apps"]//a')->item(0)->getAttribute('href'));
        $this->actingAs($driver)->get($target)->assertOk();
    }

    public function test_an_operator_sees_the_setup_line_and_the_first_step_they_can_do(): void
    {
        $operator = $this->inCompany($this->makeOperator(), 'Cape Cabs');

        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response);

        $response->assertSee('Passengers cannot book yet');
        $states = [];
        foreach ($xpath->query('//*[@data-landing-setup]/li') as $step) {
            $states[$step->getAttribute('data-step')] = $step->getAttribute('data-state');
        }
        $this->assertSame(['routing' => 'todo', 'payment' => 'todo', 'area' => 'todo', 'category' => 'done', 'tariff' => 'todo'], $states);
        $response->assertSee('EHAIL_ROUTING_DRIVER');

        $target = $this->primaryAction($xpath);
        $this->assertSame(route('ops.zones'), $target);
        $this->actingAs($operator)->get($target)->assertOk();
    }

    public function test_an_operator_opens_the_live_board_when_bookings_are_open(): void
    {
        $this->openBookings();
        $operator = $this->makeOperator();

        $xpath = $this->xpath($this->actingAs($operator)->get(route('dashboard'))->assertOk());
        $target = $this->primaryAction($xpath);

        $this->assertSame(route('ops.live'), $target);
        $this->assertSame(0, $xpath->query('//*[@data-landing-setup]')->length);
        $this->actingAs($operator)->get($target)->assertOk();
    }

    public function test_a_fleet_owner_opens_their_fleet(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        Fleet::factory()->create(['status' => 'approved', 'owner_user_id' => $owner->id]);

        $target = $this->primaryAction($this->xpath($this->actingAs($owner)->get(route('dashboard'))->assertOk()));

        $this->assertSame(route('fleet.home'), $target);
        $this->actingAs($owner)->get($target)->assertOk();
    }

    public function test_the_landing_shows_the_original_logo_files_unaltered(): void
    {
        foreach (self::LOGO_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "{$file} is the original artwork");
        }

        $xpath = $this->xpath($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard'))->assertOk());
        $main = '//main[@id="main-content"]';

        $this->assertSame(1, $xpath->query($main.'//img[@data-landing-logo][@alt="Dot.Ehail"][contains(@src, "/images/logo.png")]')->length);
        $this->assertSame(1, $xpath->query($main.'//img[contains(@src, "/images/logo-light.png")]')->length);
        $this->assertSame(0, $xpath->query($main.'//*[contains(@class, "eh-logo")]')->length, 'the redrawn mark is not used on the landing');
    }

    public function test_the_way_back_to_infodot_and_the_platforms_page(): void
    {
        $xpath = $this->xpath($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard'))->assertOk());
        $links = $xpath->query('//nav[@aria-label="Dot ecosystem"]//a');

        $this->assertSame(2, $links->length);
        $this->assertSame(['https://infodot.co.za/home', 'InfoDot'], [$links->item(0)->getAttribute('href'), trim($links->item(0)->textContent)]);
        $this->assertSame(['https://infodot.co.za/platforms', 'Platforms'], [$links->item(1)->getAttribute('href'), trim($links->item(1)->textContent)]);
    }

    public function test_a_new_member_sees_an_honest_empty_state_and_no_figures(): void
    {
        $xpath = $this->xpath($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard'))->assertOk());

        $this->assertSame(1, $xpath->query('//*[@data-landing-empty]')->length);
        $this->assertStringContainsString('No rides recorded yet.', $xpath->query('//*[@data-landing-empty]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-landing-figures]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-landing-fleet-figures]')->length);
        $this->assertSame(0, $xpath->query('//main//table')->length);
        // Nothing in the landing is a number: there are no records to count.
        $this->assertDoesNotMatchRegularExpression('/\d/', $xpath->query('//div[contains(@class, "eh-home")]')->item(0)->textContent);
    }

    public function test_every_figure_comes_from_the_seeded_records(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver(online: true);
        $this->makeRide(['status' => 'completed', 'final_fare' => 100.00, 'driver_id' => $driver->id]);
        $this->makeRide(['status' => 'completed', 'final_fare' => 250.50, 'driver_id' => $driver->id]);
        $this->makeRide(['status' => 'cancelled_by_passenger']);

        $xpath = $this->xpath($this->actingAs($operator)->get(route('dashboard'))->assertOk());
        $figures = fn (string $marker) => array_map(
            fn ($dd) => trim($dd->textContent),
            iterator_to_array($xpath->query('//*[@'.$marker.']//dd')),
        );

        // in total, on the road now, waiting for a driver, completed, cancelled
        $this->assertSame(['3', '0', '0', '2', '1'], $figures('data-landing-figures'));
        // drivers, approved, online now, completed fares
        $this->assertSame(['1', '1', '1', MoneyFormatter::format(35050)], $figures('data-landing-fleet-figures'));
        $this->assertSame(3, $xpath->query('//div[@aria-label="Recent rides"]//tbody/tr')->length);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
    }
}
