<?php

namespace App\Domain\Geo\Support;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\Routing\RoutePlanner;

/**
 * What the browser map module needs to know. Contains no secrets: tile URLs are public by nature (a keyed tile
 * URL must be restricted by HTTP referrer at the provider), routing and geocoding keys never leave the server.
 */
class GeoClientConfig
{
    public function __construct(private RoutePlanner $routes, private Geocoder $geocoder) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tiles' => [
                'url' => (string) config('ehail-geo.tiles.url'),
                'attribution' => (string) config('ehail-geo.tiles.attribution'),
                'maxZoom' => (int) config('ehail-geo.tiles.max_zoom'),
                'tileSize' => (int) config('ehail-geo.tiles.tile_size'),
                'reportIssueUrl' => config('ehail-geo.tiles.kind') === 'osm' ? 'https://www.openstreetmap.org/fixthemap' : null,
            ],
            'routing' => [
                'configured' => $this->routes->isConfigured(),
                'provider' => $this->routes->providerName(),
                'unavailableMessage' => $this->routes->isConfigured() ? null : RoutingUnavailable::describe('not_configured'),
            ],
            'geocoding' => [
                'configured' => $this->geocoder->isConfigured(),
                'provider' => $this->geocoder->providerName(),
                'autocomplete' => $this->geocoder->supportsAutocomplete(),
                'attribution' => $this->geocodingAttribution(),
            ],
            'endpoints' => [
                'search' => route('geo.search'),
                'reverse' => route('geo.reverse'),
                'route' => route('geo.route'),
                'zones' => route('geo.zones'),
            ],
            // Johannesburg CBD: a neutral first view before the user's location or a pickup is known.
            'defaultCenter' => ['lat' => -26.2041, 'lng' => 28.0473],
            'defaultZoom' => 11,
        ];
    }

    private function geocodingAttribution(): ?string
    {
        return match ($this->geocoder->providerName()) {
            'nominatim', 'photon' => 'Search data © OpenStreetMap contributors',
            'ors' => 'Search © openrouteservice.org | © OpenStreetMap contributors',
            'mapbox' => 'Search © Mapbox',
            'google' => 'Search powered by Google',
            default => null,
        };
    }
}
