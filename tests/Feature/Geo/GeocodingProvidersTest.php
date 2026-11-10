<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\LatLng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function geocoder(string $driver, array $config = []): Geocoder
    {
        config(['ehail-geo.geocoding.driver' => $driver] + $config);

        return $this->app->make(Geocoder::class);
    }

    public function test_default_is_nominatim_without_autocomplete(): void
    {
        $geocoder = $this->app->make(Geocoder::class);

        $this->assertSame('nominatim', $geocoder->providerName());
        $this->assertTrue($geocoder->isConfigured());
        $this->assertFalse($geocoder->supportsAutocomplete(), 'the Nominatim usage policy forbids autocomplete');
    }

    public function test_nominatim_search_is_country_and_near_biased_and_identified(): void
    {
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([
            ['place_id' => 123, 'lat' => '-26.1076', 'lon' => '28.0567', 'display_name' => 'Sandton City, Rivonia Road, Sandhurst, Sandton, Gauteng, 2196, South Africa', 'name' => 'Sandton City'],
            ['place_id' => 124, 'lat' => 'bad', 'lon' => '28', 'display_name' => 'Broken row'],
        ])]);

        $places = $this->geocoder('nominatim')->search('Sandton City', new LatLng(-26.2, 28.0));

        $this->assertCount(1, $places);
        $this->assertSame('Sandton City', $places[0]->name);
        $this->assertSame(-26.1076, $places[0]->position->lat);
        $this->assertSame('123', $places[0]->providerRef);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://nominatim.openstreetmap.org/search?')
            && $r['countrycodes'] === 'za'
            && $r['format'] === 'jsonv2'
            && isset($r['viewbox'])
            && str_starts_with($r->header('User-Agent')[0], 'Dot.Ehail/'));
    }

    public function test_nominatim_answers_are_cached_and_the_public_ceiling_is_one_per_second(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['place_id' => 1, 'lat' => '-33.92', 'lon' => '18.42', 'display_name' => 'Long Street, Cape Town', 'name' => 'Long Street'],
        ])]);
        $geocoder = $this->geocoder('nominatim');

        $geocoder->search('Long Street');
        $geocoder->search('long   street'); // normalised: cache hit, no second request

        try {
            $geocoder->search('Kloof Street'); // a different query inside the same second
            $this->fail('Expected GeocodingUnavailable');
        } catch (GeocodingUnavailable $e) {
            $this->assertSame('busy', $e->reason);
        }

        Http::assertSentCount(1);
    }

    public function test_short_queries_return_nothing_without_a_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->geocoder('nominatim')->search('ab'));
        Http::assertNothingSent();
    }

    public function test_nominatim_reverse(): void
    {
        Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response([
            'place_id' => 9, 'lat' => '-29.8587', 'lon' => '31.0218', 'display_name' => '1 Florence Nzama Street, Durban Central, Durban, 4001, South Africa', 'name' => '',
        ])]);

        $place = $this->geocoder('nominatim')->reverse(new LatLng(-29.85871, 31.02184));

        $this->assertSame('1 Florence Nzama Street', $place->name);
        Http::assertSent(fn (Request $r) => $r['zoom'] == 18 && $r['lat'] == -29.85871);
    }

    public function test_nominatim_reverse_with_no_answer_is_null(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'Unable to geocode'])]);

        $this->assertNull($this->geocoder('nominatim')->reverse(new LatLng(-40, 10)));
    }

    public function test_photon_filters_to_the_country_and_builds_labels(): void
    {
        Http::fake(['photon.example.test/api*' => Http::response(['features' => [
            ['geometry' => ['coordinates' => [18.4241, -33.9249]], 'properties' => ['name' => 'Greenmarket Square', 'city' => 'Cape Town', 'state' => 'Western Cape', 'countrycode' => 'ZA', 'osm_type' => 'W', 'osm_id' => 42]],
            ['geometry' => ['coordinates' => [-0.12, 51.5]], 'properties' => ['name' => 'Greenmarket', 'city' => 'London', 'countrycode' => 'GB']],
        ]])]);

        $geocoder = $this->geocoder('photon', ['ehail-geo.providers.photon.url' => 'https://photon.example.test']);
        $places = $geocoder->search('Greenmarket', new LatLng(-33.9, 18.4));

        $this->assertTrue($geocoder->supportsAutocomplete());
        $this->assertCount(1, $places);
        $this->assertSame('Greenmarket Square, Cape Town, Western Cape', $places[0]->label);
        $this->assertSame('W42', $places[0]->providerRef);
        Http::assertSent(fn (Request $r) => $r['lat'] == -33.9 && $r['lon'] == 18.4);
    }

    public function test_ors_autocomplete_and_reverse(): void
    {
        Http::fake([
            'api.openrouteservice.org/geocode/autocomplete*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [28.2293, -25.7479]], 'properties' => ['label' => 'Church Square, Pretoria, South Africa', 'name' => 'Church Square', 'gid' => 'openstreetmap:venue:node/1']],
            ]]),
            'api.openrouteservice.org/geocode/reverse*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [28.2293, -25.7479]], 'properties' => ['label' => 'Church Square, Pretoria', 'name' => 'Church Square']],
            ]]),
        ]);

        $geocoder = $this->geocoder('ors', ['ehail-geo.providers.ors.key' => 'ors-key']);

        $this->assertSame('Church Square', $geocoder->search('Church Sq')[0]->name);
        $this->assertSame('Church Square, Pretoria', $geocoder->reverse(new LatLng(-25.7479, 28.2293))->label);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/geocode/autocomplete') && $r['boundary.country'] === 'ZA' && $r['api_key'] === 'ors-key');
    }

    public function test_mapbox_v6_forward_and_reverse_are_not_cached(): void
    {
        Http::fake([
            'api.mapbox.com/search/geocode/v6/forward*' => Http::response(['type' => 'FeatureCollection', 'features' => [
                ['geometry' => ['coordinates' => [31.1197, -29.6144]], 'properties' => ['mapbox_id' => 'dXJu', 'name' => 'King Shaka International Airport', 'full_address' => 'King Shaka International Airport, La Mercy, KwaZulu-Natal, South Africa']],
            ]]),
            'api.mapbox.com/search/geocode/v6/reverse*' => Http::response(['features' => []]),
        ]);

        $geocoder = $this->geocoder('mapbox', ['ehail-geo.providers.mapbox.token' => 'pk.x']);
        $geocoder->search('King Shaka');
        $places = $geocoder->search('King Shaka');

        $this->assertSame('King Shaka International Airport', $places[0]->name);
        $this->assertNull($geocoder->reverse(new LatLng(-29.6, 31.1)));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/forward') && $r['country'] === 'za' && $r['autocomplete'] === 'true');
    }

    public function test_google_text_search_and_reverse(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => [
                ['id' => 'ChIJ1', 'displayName' => ['text' => 'O. R. Tambo International Airport'], 'formattedAddress' => 'O.R. Tambo Airport Rd, Kempton Park, 1632, South Africa', 'location' => ['latitude' => -26.1337, 'longitude' => 28.242]],
            ]]),
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);

        $geocoder = $this->geocoder('google', ['ehail-geo.providers.google.key' => 'g-key']);
        $places = $geocoder->search('OR Tambo', new LatLng(-26.1, 28.1));

        $this->assertSame('ChIJ1', $places[0]->providerRef);
        $this->assertSame(28.242, $places[0]->position->lng);
        $this->assertNull($geocoder->reverse(new LatLng(-26.13, 28.24)));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'places:searchText') && $r->hasHeader('X-Goog-Api-Key', 'g-key')
            && $r['regionCode'] === 'ZA' && isset($r['locationBias']['circle']));
    }

    public function test_google_reverse_denied_is_a_provider_error(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'])]);

        try {
            $this->geocoder('google', ['ehail-geo.providers.google.key' => 'g'])->reverse(new LatLng(-26, 28));
            $this->fail('Expected GeocodingUnavailable');
        } catch (GeocodingUnavailable $e) {
            $this->assertSame('provider_error', $e->reason);
        }
    }

    public function test_none_is_honestly_unavailable(): void
    {
        Http::fake();

        try {
            $this->geocoder('none')->search('Sandton');
            $this->fail('Expected GeocodingUnavailable');
        } catch (GeocodingUnavailable $e) {
            $this->assertSame('not_configured', $e->reason);
            $this->assertStringContainsString('Place the pin on the map', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
