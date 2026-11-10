<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use Illuminate\Http\Client\Response;

/**
 * Mapbox Directions API v5, driving profile, polyline (precision 5) geometry.
 */
class MapboxDirectionsDriver extends HttpRoutingDriver
{
    public const ENDPOINT = 'https://api.mapbox.com/directions/v5/mapbox/driving/';

    public function name(): string
    {
        return 'mapbox';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.mapbox.token'));
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        $coordinates = sprintf('%F,%F;%F,%F', $from->lng, $from->lat, $to->lng, $to->lat);
        $query = array_filter([
            'access_token' => (string) config('ehail-geo.providers.mapbox.token'),
            'geometries' => 'polyline',
            'overview' => 'full',
            'alternatives' => 'false',
            'exclude' => $options->avoidTolls ? 'toll' : null,
        ]);

        $response = $this->send(fn ($http) => $http->get(self::ENDPOINT.$coordinates, $query));

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
