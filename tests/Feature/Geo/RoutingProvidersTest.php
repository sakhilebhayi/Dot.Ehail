<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\RouteCacheEntry;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RoutePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each routing adapter against the provider's real request and response shapes (Http::fake, no network).
 */
class RoutingProvidersTest extends TestCase
{
    use RefreshDatabase;

    private LatLng $sandton;

    private LatLng $rosebank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandton = new LatLng(-26.1076, 28.0567);
        $this->rosebank = new LatLng(-26.1467, 28.0436);
        Http::preventStrayRequests();
    }

    private function planner(string $driver, array $config = []): RoutePlanner
    {
        config(['ehail-geo.routing.driver' => $driver] + $config);

        return $this->app->make(RoutePlanner::class);
    }

    public function test_unconfigured_routing_is_honest_and_makes_no_request(): void
    {
        $planner = $this->planner('none');

        $this->assertFalse($planner->isConfigured());

        try {
            $planner->route($this->sandton, $this->rosebank);
            $this->fail('Expected RoutingUnavailable');
        } catch (RoutingUnavailable $e) {
            $this->assertSame('not_configured', $e->reason);
            $this->assertSame('Route pricing is unavailable: the operator has not configured a routing provider.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_driver_without_its_key_counts_as_unconfigured(): void
    {
        $this->expectExceptionObject(RoutingUnavailable::notConfigured());
        $this->planner('google', ['ehail-geo.providers.google.key' => null])->route($this->sandton, $this->rosebank);
    }

    public function test_google_routes_request_and_response(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response([
            'routes' => [['distanceMeters' => 5321, 'duration' => '612s', 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC']]],
        ])]);

        $route = $this->planner('google', ['ehail-geo.providers.google.key' => 'g-key'])->route($this->sandton, $this->rosebank, new RouteOptions(avoidTolls: true));

        $this->assertSame(5321, $route->distanceM);
        $this->assertSame(612, $route->durationS);
        $this->assertSame('google', $route->provider);
        $this->assertSame('road', $route->kind);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
            && $r->hasHeader('X-Goog-Api-Key', 'g-key')
            && str_contains($r->header('X-Goog-FieldMask')[0], 'routes.distanceMeters')
            && $r['origin']['location']['latLng']['latitude'] === -26.1076
            && $r['travelMode'] === 'DRIVE'
            && $r['routeModifiers']['avoidTolls'] === true);
    }

    public function test_google_empty_response_means_no_route(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response([])]);

        try {
            $this->planner('google', ['ehail-geo.providers.google.key' => 'g'])->route($this->sandton, $this->rosebank);
            $this->fail('Expected RoutingUnavailable');
        } catch (RoutingUnavailable $e) {
            $this->assertSame('no_route', $e->reason);
        }
    }

    public function test_mapbox_directions_request_and_response(): void
    {
        Http::fake(['api.mapbox.com/directions/*' => Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => 5320.4, 'duration' => 611.6, 'geometry' => '_p~iF~ps|U']],
        ])]);

        $route = $this->planner('mapbox', ['ehail-geo.providers.mapbox.token' => 'pk.test'])->route($this->sandton, $this->rosebank);

        $this->assertSame(5320, $route->distanceM);
        $this->assertSame(612, $route->durationS);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.mapbox.com/directions/v5/mapbox/driving/28.056700,-26.107600;28.043600,-26.146700?')
            && $r['access_token'] === 'pk.test' && $r['geometries'] === 'polyline');
    }

    public function test_mapbox_no_route_code(): void
    {
        Http::fake(['api.mapbox.com/*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);

        $this->expectExceptionObject(new RoutingUnavailable('no_route', 'mapbox'));
        $this->planner('mapbox', ['ehail-geo.providers.mapbox.token' => 'pk'])->route($this->sandton, $this->rosebank);
    }

    public function test_ors_directions_request_and_response(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'routes' => [['summary' => ['distance' => 5400.2, 'duration' => 640.4], 'geometry' => '_p~iF~ps|U']],
        ])]);

        $route = $this->planner('ors', ['ehail-geo.providers.ors.key' => 'ors-key'])->route($this->sandton, $this->rosebank);

        $this->assertSame(5400, $route->distanceM);
        $this->assertSame(640, $route->durationS);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openrouteservice.org/v2/directions/driving-car'
            && $r->hasHeader('Authorization', 'ors-key')
            && $r['coordinates'] === [[28.0567, -26.1076], [28.0436, -26.1467]]);
    }

    public function test_ors_route_not_found_error_is_no_route_not_a_fault(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response(['error' => ['code' => 2009, 'message' => 'Route could not be found']], 404)]);

        try {
            $this->planner('ors', ['ehail-geo.providers.ors.key' => 'k'])->route($this->sandton, $this->rosebank);
            $this->fail('Expected RoutingUnavailable');
        } catch (RoutingUnavailable $e) {
            $this->assertSame('no_route', $e->reason);
        }
    }

    public function test_osrm_request_and_response(): void
    {
        Http::fake(['osrm.example.test/*' => Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => 5288.9, 'duration' => 590.1, 'geometry' => '_p~iF~ps|U']],
        ])]);

        $route = $this->planner('osrm', ['ehail-geo.providers.osrm.url' => 'https://osrm.example.test/'])->route($this->sandton, $this->rosebank);

        $this->assertSame(5289, $route->distanceM);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://osrm.example.test/route/v1/driving/28.056700,-26.107600;28.043600,-26.146700?')
            && $r['overview'] === 'full');
    }

    public function test_valhalla_precision_six_shape_is_reencoded_to_precision_five(): void
    {
        $points = [new LatLng(-26.1076, 28.0567), new LatLng(-26.1200, 28.0500), new LatLng(-26.1467, 28.0436)];
        Http::fake(['valhalla.example.test/*' => Http::response([
            'trip' => ['summary' => ['length' => 5.43, 'time' => 650.2], 'legs' => [['shape' => Polyline::encode($points, 6)]], 'status' => 0],
        ])]);

        $route = $this->planner('valhalla', ['ehail-geo.providers.valhalla.url' => 'https://valhalla.example.test'])->route($this->sandton, $this->rosebank);

        $this->assertSame(5430, $route->distanceM);
        $this->assertSame(650, $route->durationS);
        $decoded = Polyline::decode($route->polyline, 5);
        $this->assertCount(3, $decoded);
        $this->assertEqualsWithDelta(-26.12, $decoded[1]->lat, 1e-5);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://valhalla.example.test/route' && $r['costing'] === 'auto'
            && $r['locations'][0] === ['lat' => -26.1076, 'lon' => 28.0567]);
    }

    public function test_routes_are_cached_by_rounded_coordinates(): void
    {
        Http::fake(['osrm.example.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 5000, 'duration' => 600, 'geometry' => '']]])]);
        $planner = $this->planner('osrm', ['ehail-geo.providers.osrm.url' => 'https://osrm.example.test']);

        $first = $planner->route($this->sandton, $this->rosebank);
        // Two metres away: same rounded key.
        $second = $planner->route(new LatLng(-26.10761, 28.05671), $this->rosebank);

        $this->assertFalse($first->fromCache);
        $this->assertTrue($second->fromCache);
        $this->assertSame(5000, $second->distanceM);
        Http::assertSentCount(1);
        $this->assertSame(1, RouteCacheEntry::count());
    }

    public function test_expired_cache_entries_are_not_used(): void
    {
        Http::fake(['osrm.example.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 5000, 'duration' => 600, 'geometry' => '']]])]);
        $planner = $this->planner('osrm', ['ehail-geo.providers.osrm.url' => 'https://osrm.example.test']);

        $planner->route($this->sandton, $this->rosebank);
        $this->travel(11)->minutes();
        $planner->route($this->sandton, $this->rosebank);

        Http::assertSentCount(2);
    }

    public function test_timeouts_open_the_circuit_breaker_and_then_fail_fast(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $planner = $this->planner('osrm', ['ehail-geo.providers.osrm.url' => 'https://osrm.example.test']);

        for ($i = 0; $i < 5; $i++) {
            try {
                $planner->route($this->sandton, new LatLng(-26.0 - $i / 100, 28.0));
            } catch (RoutingUnavailable $e) {
                $this->assertSame('timeout', $e->reason);
            }
        }

        try {
            $planner->route($this->sandton, $this->rosebank);
            $this->fail('Expected RoutingUnavailable');
        } catch (RoutingUnavailable $e) {
            $this->assertSame('circuit_open', $e->reason);
        }

        Http::assertSentCount(0); // ConnectionException fakes are thrown before a request is recorded.
    }

    public function test_server_errors_count_as_provider_errors(): void
    {
        Http::fake(['osrm.example.test/*' => Http::response('upstream down', 502)]);

        try {
            $this->planner('osrm', ['ehail-geo.providers.osrm.url' => 'https://osrm.example.test'])->route($this->sandton, $this->rosebank);
            $this->fail('Expected RoutingUnavailable');
        } catch (RoutingUnavailable $e) {
            $this->assertSame('provider_error', $e->reason);
            $this->assertStringContainsString('temporarily unavailable', $e->getMessage());
        }
    }
}
