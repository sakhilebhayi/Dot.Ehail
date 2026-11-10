<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use Illuminate\Http\Client\Response;

/**
 * OSRM HTTP API v5 on an operator-run or contracted server (EHAIL_OSRM_URL). The public demo server is
 * non-commercial only and is never configured by default.
 */
class OsrmDriver extends HttpRoutingDriver
{
    public function name(): string
    {
        return 'osrm';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.osrm.url'));
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        $url = rtrim((string) config('ehail-geo.providers.osrm.url'), '/')
            .sprintf('/route/v1/driving/%F,%F;%F,%F', $from->lng, $from->lat, $to->lng, $to->lat);

        $response = $this->send(fn ($http) => $http->get($url, [
            'overview' => 'full',
            'geometries' => 'polyline',
            'alternatives' => 'false',
            'steps' => 'false',
        ]));

        if ($response->json('code') !== 'Ok') {
            throw in_array($response->json('code'), ['NoRoute', 'NoSegment'], true) ? $this->noRoute() : $this->invalid();
        }

        $route = $response->json('routes.0');

        if (! is_array($route) || ! isset($route['distance'], $route['duration'])) {
            throw $this->noRoute();
        }

        return new RouteResult((int) round($route['distance']), (int) round($route['duration']), (string) ($route['geometry'] ?? ''), $this->name());
    }

    protected function noRouteStatus(Response $response): bool
    {
        return in_array($response->json('code'), ['NoRoute', 'NoSegment'], true);
    }
}
