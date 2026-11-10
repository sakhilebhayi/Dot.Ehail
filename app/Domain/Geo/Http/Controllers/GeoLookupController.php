<?php

namespace App\Domain\Geo\Http\Controllers;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Domain\Geo\Support\GeoClientConfig;
use App\Domain\Geo\Zones\ZoneResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side proxy for search, reverse geocoding, routes and zone lookups, so provider keys never reach the
 * browser. Every unavailable state answers 503 with a reason code and a sentence the UI can show as is.
 */
class GeoLookupController extends Controller
{
    public function config(GeoClientConfig $config): JsonResponse
    {
        return response()->json($config->toArray());
    }

    public function search(Request $request, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $near = isset($data['lat'], $data['lng']) ? LatLng::from($data['lat'], $data['lng']) : null;

        try {
            $places = $geocoder->search($data['q'], $near);
        } catch (GeocodingUnavailable $e) {
            return $this->geocodingUnavailable($e);
        }

        return response()->json([
            'results' => array_map(fn (Place $p) => $p->toArray(), $places),
            'provider' => $geocoder->providerName(),
            'autocomplete' => $geocoder->supportsAutocomplete(),
        ]);
    }

    public function reverse(Request $request, Geocoder $geocoder): JsonResponse
    {
        $point = $this->point($request, 'lat', 'lng');

        try {
            $place = $geocoder->reverse($point);
        } catch (GeocodingUnavailable $e) {
            return $this->geocodingUnavailable($e);
        }

        return response()->json(['result' => $place?->toArray(), 'provider' => $geocoder->providerName()]);
    }

    public function route(Request $request, RoutePlanner $routes, ZoneResolver $zones): JsonResponse
    {
        $from = $this->point($request, 'from_lat', 'from_lng');
        $to = $this->point($request, 'to_lat', 'to_lng');

        try {
            $route = $routes->route($from, $to);
        } catch (RoutingUnavailable $e) {
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
                'provider' => $e->provider,
            ], 503);
        }

        return response()->json([
            'route' => $route->toArray(),
            'pickup_serviced' => $zones->isServiced($from),
        ]);
    }

    public function zones(Request $request, ZoneResolver $zones): JsonResponse
    {
        $point = $this->point($request, 'lat', 'lng');
        $area = $zones->serviceAreaFor($point);

        return response()->json([
            'service_area' => $area ? $this->areaPayload($area) : null,
            'zones' => array_map(fn (Zone $z) => [
                'slug' => $z->slug,
                'name' => $z->name,
                'kind' => $z->kind,
                'fee_code' => $z->fee_code,
                'approximate' => $z->is_approximate,
            ], $zones->zonesFor($point)),
        ]);
    }

    private function point(Request $request, string $latKey, string $lngKey): LatLng
    {
        $data = $request->validate([
            $latKey => ['required', 'numeric', 'between:-90,90'],
            $lngKey => ['required', 'numeric', 'between:-180,180'],
        ]);

        return LatLng::from($data[$latKey], $data[$lngKey]);
    }

    /**
     * @return array<string, mixed>
     */
    private function areaPayload(ServiceArea $area): array
    {
        return [
            'slug' => $area->slug,
            'name' => $area->name,
            'country_code' => $area->country_code,
            'currency' => $area->currency,
            'timezone' => $area->timezone,
            'approximate' => $area->is_approximate,
        ];
    }

    private function geocodingUnavailable(GeocodingUnavailable $e): JsonResponse
    {
        $status = $e->reason === 'busy' ? 429 : 503;
        $response = response()->json(['reason' => $e->reason, 'message' => $e->getMessage(), 'provider' => $e->provider], $status);

        return $e->reason === 'busy' ? $response->header('Retry-After', '1') : $response;
    }
}
