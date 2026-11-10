<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;

/**
 * Google Maps Platform Routes API, computeRoutes (traffic-unaware, the Essentials SKU). Server key only.
 */
class GoogleRoutesDriver extends HttpRoutingDriver
{
    public const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.google.key'));
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        $response = $this->send(fn ($http) => $http
            ->withHeaders([
                'X-Goog-Api-Key' => (string) config('ehail-geo.providers.google.key'),
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
            ])
            ->post(self::ENDPOINT, [
                'origin' => ['location' => ['latLng' => ['latitude' => $from->lat, 'longitude' => $from->lng]]],
                'destination' => ['location' => ['latLng' => ['latitude' => $to->lat, 'longitude' => $to->lng]]],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE',
                'routeModifiers' => ['avoidTolls' => $options->avoidTolls],
                'polylineEncoding' => 'ENCODED_POLYLINE',
                'units' => 'METRIC',
            ]));

        $route = $response->json('routes.0');

        if ($route === null) {
            throw $this->noRoute();
        }

        $duration = (string) ($route['duration'] ?? '');

        if (! isset($route['distanceMeters']) || ! preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $m)) {
            throw $this->invalid();
        }

        return new RouteResult(
            (int) $route['distanceMeters'],
            (int) round((float) $m[1]),
            (string) ($route['polyline']['encodedPolyline'] ?? ''),
            $this->name(),
        );
    }
}
