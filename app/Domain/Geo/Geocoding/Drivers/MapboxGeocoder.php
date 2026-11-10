<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

/**
 * Mapbox Geocoding API v6 (temporary geocoding: results are shown, not stored; caching is off by default).
 */
class MapboxGeocoder extends HttpGeocodingDriver
{
    public const BASE = 'https://api.mapbox.com/search/geocode/v6';

    public function name(): string
    {
        return 'mapbox';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.mapbox.token'));
    }

    public function supportsAutocomplete(): bool
    {
        return true;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        $params = [
            'q' => $query,
            'access_token' => (string) config('ehail-geo.providers.mapbox.token'),
            'country' => strtolower($country),
            'autocomplete' => 'true',
            'limit' => min($limit, 10),
            'language' => $language,
        ];

        if ($near !== null) {
            $params['proximity'] = sprintf('%F,%F', $near->lng, $near->lat);
        }

        $features = $this->send(fn ($http) => $http->get(self::BASE.'/forward', $params))->json('features');

        return collect(is_array($features) ? $features : [])->map(fn ($f) => $this->place($f))->filter()->values()->all();
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        $feature = $this->send(fn ($http) => $http->get(self::BASE.'/reverse', [
            'longitude' => $point->lng,
            'latitude' => $point->lat,
            'access_token' => (string) config('ehail-geo.providers.mapbox.token'),
            'limit' => 1,
            'language' => $language,
        ]))->json('features.0');

        return is_array($feature) ? $this->place($feature) : null;
    }

    private function place(mixed $feature): ?Place
    {
        $coordinates = data_get($feature, 'geometry.coordinates');
        $position = is_array($coordinates) ? $this->point($coordinates[1] ?? null, $coordinates[0] ?? null) : null;
        $name = trim((string) data_get($feature, 'properties.name', ''));
        $label = trim((string) data_get($feature, 'properties.full_address', ''));

        if ($label === '') {
            $label = trim($name.', '.data_get($feature, 'properties.place_formatted', ''), ', ');
        }

        if ($position === null || $label === '') {
            return null;
        }

        $ref = data_get($feature, 'properties.mapbox_id');

        return new Place($label, $name !== '' ? $name : $label, $position, $this->name(), $ref !== null ? (string) $ref : null);
    }
}
