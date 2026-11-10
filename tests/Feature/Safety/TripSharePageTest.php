<?php

namespace Tests\Feature\Safety;

use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\RideRoute;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Domain\Safety\TripShareRoute;
use App\Domain\Safety\TripShareService;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

/**
 * The public trip-share page in the brand (R-46 ppass, D-2011): areas instead of addresses or coordinates, the
 * sharer's first name, times in the service's zone, and a road line that stops short of both doors.
 */
class TripSharePageTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    /** A straight road north from the pickup, one point about every 111 m. */
    private function plan(Ride $ride, int $points): RideRoute
    {
        $line = [];
        for ($i = 0; $i < $points; $i++) {
            $line[] = new LatLng(-26.2500 + $i * 0.001, 27.8500);
        }

        return RideRoute::create([
            'ride_id' => $ride->id, 'provider' => 'test', 'planned_distance_m' => (int) Haversine::pathMetres($line),
            'planned_duration_s' => 600, 'planned_polyline' => Polyline::encode($line), 'planned_at' => now(),
        ]);
    }

    private function seeCarAt(float $lat, float $lng, ?int $eta): void
    {
        $this->app->instance(TripProgressSource::class, new class($lat, $lng, $eta) implements TripProgressSource
        {
            public function __construct(private float $lat, private float $lng, private ?int $eta) {}

            public function progress(Ride $ride): array
            {
                return ['state' => (string) $ride->status, 'driver_position' => ['lat' => $this->lat, 'lng' => $this->lng], 'position_at' => '2026-11-10T12:30:05+00:00', 'eta_seconds' => $this->eta, 'eta_source' => 'test'];
            }
        });
    }

    public function test_the_page_names_areas_and_people_by_first_name_and_never_shows_coordinates(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $this->seeCarAt(-26.2531847, 27.8512399, 540);
        $token = app(TripShareService::class)->create($ride, $passenger)['token'];

        $page = $this->get('/t/'.$token)->assertOk();

        $page->assertSee('Thandi is sharing this trip with you.')
            ->assertSee('Trip in progress')
            ->assertSee('About 9 min to drop-off')
            ->assertSee('Soweto')
            ->assertSee('Sandton')
            ->assertSee('Worried about Thandi?')
            ->assertSee('Last seen 14:30:05 SAST')
            ->assertSee('10111')
            ->assertSee('data-geo-config', false)
            ->assertDontSee('openstreetmap.org/?mlat', false)
            ->assertDontSee('IBM Plex', false);

        // The visible text carries no coordinate; the only position on the page is the rounded one the map reads.
        $visible = preg_replace('#<script\b.*?</script>#s', '', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/-?\d{1,3}\.\d{3,}/', strip_tags($visible));
        $this->assertStringNotContainsString('26.2531847', $page->getContent());
        $this->assertStringContainsString('-26.253', $page->getContent());

        $this->assertSame([], $page->headers->getCookies());
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
    }

    public function test_the_poll_gives_ready_to_read_times_in_the_service_zone_and_no_route(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $this->plan($ride, 40);
        $this->seeCarAt(-26.2531847, 27.8512399, 61);
        $token = app(TripShareService::class)->create($ride, $passenger)['token'];

        $this->travelTo('2026-11-10 12:31:00');
        $json = $this->getJson('/t/'.$token.'/status')->assertOk();

        $json->assertJsonPath('sharer_first_name', 'Thandi')
            ->assertJsonPath('shared_by', 'passenger')
            ->assertJsonPath('from_area', 'Soweto')
            ->assertJsonPath('to_area', 'Sandton')
            ->assertJsonPath('eta_to', 'dropoff')
            ->assertJsonPath('eta_text', 'About 1 min to drop-off')
            ->assertJsonPath('approximate_position.lat', -26.253)
            ->assertJsonPath('approximate_position.time', '14:30:05')
            ->assertJsonPath('checked_time', '14:31:00')
            ->assertJsonPath('time_zone', 'SAST')
            ->assertJsonMissingPath('route')
            ->assertJsonMissingPath('map');
        $this->assertSame(12, strlen($json->json('route_key')));
        $this->assertStringNotContainsString('Road', $json->getContent());
    }

    public function test_a_share_made_by_the_driver_says_so(): void
    {
        [$ride, , $driver] = $this->makeActiveRide('accepted');
        $this->seeCarAt(-26.25, 27.85, 300);
        $token = app(TripShareService::class)->create($ride, $driver)['token'];
        $first = strtok($driver->name, ' ');

        $this->get('/t/'.$token)->assertOk()
            ->assertSee($first.', the driver, is sharing this trip with you.')
            ->assertSee('Worried about '.$first.'?')
            ->assertSee('About 5 min to pickup');
        $this->getJson('/t/'.$token.'/status')->assertJsonPath('shared_by', 'driver')->assertJsonPath('eta_to', 'pickup');
    }

    public function test_the_map_line_stops_short_of_both_doors(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $route = $this->plan($ride, 40);
        $share = app(TripShareService::class)->create($ride, $passenger)['share']->load('ride');

        $full = $route->plannedPoints();
        $drawn = Polyline::decode((string) app(TripShareService::class)->publicMap($share)['route']);

        $this->assertGreaterThanOrEqual(300, Haversine::metres($full[0], $drawn[0]));
        $this->assertGreaterThanOrEqual(300, Haversine::metres($full[count($full) - 1], $drawn[count($drawn) - 1]));
        $this->assertLessThan(count($full), count($drawn));

        // A road too short to hide its ends draws no line at all, and the key changes with the destination.
        $route->update(['planned_polyline' => Polyline::encode(array_slice($full, 0, 6))]);
        $this->assertNull(TripShareRoute::trimmed($ride));

        $before = TripShareRoute::key($ride);
        $ride->forceFill(['dropoff_lat' => -26.1, 'dropoff_lng' => 28.05])->save();
        $this->assertNotSame($before, TripShareRoute::key($ride->refresh()));
    }

    public function test_an_ended_trip_and_a_dead_link_show_no_map_and_load_no_script(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $this->plan($ride, 40);
        $token = app(TripShareService::class)->create($ride, $passenger)['token'];
        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->get('/t/'.$token)->assertOk()
            ->assertSee('Trip completed')
            ->assertDontSee('data-eh-map', false)
            ->assertDontSee('data-geo-config', false)
            ->assertDontSee('http-equiv="refresh"', false);

        $this->get('/t/'.str_repeat('a', 43))->assertNotFound()
            ->assertSee('This link is not available')
            ->assertSee('10111')
            ->assertDontSee('data-eh-map', false);
    }
}
