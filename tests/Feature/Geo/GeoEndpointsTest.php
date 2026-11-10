<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->withPersonalTeam()->create();
    }

    public function test_guests_cannot_use_the_proxy(): void
    {
        // JSON-only API paths answer 401 JSON even without an Accept header (the app-wide policy, D-711).
        $this->get(route('geo.search', ['q' => 'Sandton']))->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        $this->get(route('geo.route', ['from_lat' => -26, 'from_lng' => 28, 'to_lat' => -26.1, 'to_lng' => 28.1]))->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    }

    public function test_route_without_a_provider_says_so_and_offers_no_distance(): void
    {
        config(['ehail-geo.routing.driver' => 'none']);

        $this->actingAs($this->user)
            ->getJson(route('geo.route', ['from_lat' => -26.1076, 'from_lng' => 28.0567, 'to_lat' => -26.1467, 'to_lng' => 28.0436]))
            ->assertStatus(503)
            ->assertExactJson([
                'reason' => 'not_configured',
                'message' => 'Route pricing is unavailable: the operator has not configured a routing provider.',
                'provider' => 'none',
            ]);
    }

    public function test_route_with_a_provider_returns_the_road_route(): void
    {
        config(['ehail-geo.routing.driver' => 'osrm', 'ehail-geo.providers.osrm.url' => 'https://osrm.example.test']);
        Http::fake(['osrm.example.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 5288.9, 'duration' => 590.1, 'geometry' => '_p~iF~ps|U']]])]);

        $this->actingAs($this->user)
            ->getJson(route('geo.route', ['from_lat' => -26.1076, 'from_lng' => 28.0567, 'to_lat' => -26.1467, 'to_lng' => 28.0436]))
            ->assertOk()
            ->assertJsonPath('route.distance_m', 5289)
            ->assertJsonPath('route.kind', 'road')
            ->assertJsonPath('route.provider', 'osrm')
            ->assertJsonPath('pickup_serviced', false);
    }

    public function test_route_validates_coordinates(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('geo.route', ['from_lat' => 95, 'from_lng' => 28, 'to_lat' => -26, 'to_lng' => 28]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_lat');
    }

    public function test_search_proxies_to_the_configured_geocoder(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['place_id' => 1, 'lat' => '-26.1076', 'lon' => '28.0567', 'display_name' => 'Sandton City, Sandton', 'name' => 'Sandton City'],
        ])]);

        $this->actingAs($this->user)
            ->getJson(route('geo.search', ['q' => 'Sandton City', 'lat' => -26.2, 'lng' => 28.0]))
            ->assertOk()
            ->assertJsonPath('results.0.name', 'Sandton City')
            ->assertJsonPath('autocomplete', false)
            ->assertJsonPath('provider', 'nominatim');
    }

    public function test_search_busy_answers_429_with_retry_after(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'First query']))->assertOk();
        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'Second query']))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'busy');
    }

    public function test_search_without_a_geocoder_is_503(): void
    {
        config(['ehail-geo.geocoding.driver' => 'none']);

        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'Sandton']))
            ->assertStatus(503)
            ->assertJsonPath('reason', 'not_configured');
    }

    public function test_search_validates_the_query(): void
    {
        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'ab']))->assertUnprocessable();
        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'Sandton', 'lat' => -26]))->assertUnprocessable();
    }

    public function test_search_is_rate_limited_per_user(): void
    {
        config(['ehail-geo.providers.nominatim.requests_per_second' => 0]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'Query '.$i]))->assertOk();
        }

        $this->actingAs($this->user)->getJson(route('geo.search', ['q' => 'One too many']))->assertStatus(429);
    }

    public function test_client_config_carries_no_secrets(): void
    {
        config([
            'ehail-geo.routing.driver' => 'google',
            'ehail-geo.providers.google.key' => 'SECRET-SERVER-KEY',
            'ehail-geo.geocoding.driver' => 'google',
        ]);

        $response = $this->actingAs($this->user)->getJson(route('geo.config'))
            ->assertOk()
            ->assertJsonPath('routing.configured', true)
            ->assertJsonPath('geocoding.autocomplete', true)
            ->assertJsonPath('tiles.url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');

        $this->assertStringNotContainsString('SECRET-SERVER-KEY', $response->getContent());
    }

    public function test_client_config_reports_unconfigured_routing_honestly(): void
    {
        $this->actingAs($this->user)->getJson(route('geo.config'))
            ->assertJsonPath('routing.configured', false)
            ->assertJsonPath('routing.unavailableMessage', 'Route pricing is unavailable: the operator has not configured a routing provider.')
            ->assertJsonPath('tiles.reportIssueUrl', 'https://www.openstreetmap.org/fixthemap');
    }

    public function test_the_demo_page_is_404_outside_local(): void
    {
        $this->actingAs($this->user)->get(route('geo.demo'))->assertNotFound();
    }

    public function test_the_demo_page_renders_in_local_with_geolocation_allowed(): void
    {
        $this->withoutVite();
        $this->app['env'] = 'local';

        $this->actingAs($this->user)->get(route('geo.demo'))
            ->assertOk()
            ->assertSee('data-geo-demo', false)
            ->assertHeader('Permissions-Policy', AllowGeolocation::POLICY);
    }
}
