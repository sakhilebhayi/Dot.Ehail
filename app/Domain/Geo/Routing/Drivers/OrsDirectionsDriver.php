<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use Illuminate\Http\Client\Response;

/**
 * openrouteservice Directions v2, driving-car, JSON response (geometry is an encoded polyline, precision 5).
 */
class OrsDirectionsDriver extends HttpRoutingDriver
{
    public function name(): string
    {
        return 'ors';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.ors.key'));
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        $body = ['coordinates' => [[$from->lng, $from->lat], [$to->lng, $to->lat]], 'instructions' => false];

        if ($options->avoidTolls) {
            $body['options'] = ['avoid_features' => ['tollways']];
        }

        $url = rtrim((string) config('ehail-geo.providers.ors.url'), '/').'/v2/directions/driving-car';
        $response = $this->send(fn ($http) => $http
            ->withHeaders(['Authorization' => (string) config('ehail-geo.providers.ors.key')])
            ->post($url, $body));

        $route = $response->json('routes.0');

        if (! is_array($route) || ! isset($route['summary'])) {
            throw $this->noRoute();
        }

        // ORS omits distance/duration from the summary when start and end coincide.
        return new RouteResult(
            (int) round((float) ($route['summary']['distance'] ?? 0)),
            (int) round((float) ($route['summary']['duration'] ?? 0)),
            (string) ($route['geometry'] ?? ''),
            $this->name(),
        );
    }

    protected function noRouteStatus(Response $response): bool
    {
        // 2009: route could not be found; 2010: point not within routable distance of a road.
        return in_array((int) $response->json('error.code'), [2009, 2010], true);
    }
}
