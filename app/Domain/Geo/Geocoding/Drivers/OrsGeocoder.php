<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

/**
 * openrouteservice geocoding (Pelias): /geocode/autocomplete and /geocode/reverse. Needs EHAIL_ORS_KEY.
 */
class OrsGeocoder extends HttpGeocodingDriver
{
    public function name(): string
    {
        return 'ors';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.ors.key'));
    }

    public function supportsAutocomplete(): bool
    {
        return true;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        $params = [
            'api_key' => (string) config('ehail-geo.providers.ors.key'),
            'text' => $query,
            'boundary.country' => strtoupper($country),
            'size' => $limit,
            'lang' => $language,
        ];

        if ($near !== null) {
            $params['focus.point.lat'] = $near->lat;
            $params['focus.point.lon'] = $near->lng;
        }

        $features = $this->send(fn ($http) => $http->get($this->url('/geocode/autocomplete'), $params))->json('features');

        return collect(is_array($features) ? $features : [])->map(fn ($f) => $this->place($f))->filter()->values()->all();
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        $feature = $this->send(fn ($http) => $http->get($this->url('/geocode/reverse'), [
            'api_key' => (string) config('ehail-geo.providers.ors.key'),
            'point.lat' => $point->lat,
            'point.lon' => $point->lng,
            'size' => 1,
            'lang' => $language,
        ]))->json('features.0');

        return is_array($feature) ? $this->place($feature) : null;
    }

    private function place(mixed $feature): ?Place
    {
        $coordinates = data_get($feature, 'geometry.coordinates');
        $position = is_array($coordinates) ? $this->point($coordinates[1] ?? null, $coordinates[0] ?? null) : null;
        $label = trim((string) data_get($feature, 'properties.label', ''));

        if ($position === null || $label === '') {
            return null;
        }

        $name = trim((string) data_get($feature, 'properties.name', '')) ?: $label;
        $ref = data_get($feature, 'properties.gid') ?? data_get($feature, 'properties.id');

        return new Place($label, $name, $position, $this->name(), $ref !== null ? (string) $ref : null);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ehail-geo.providers.ors.url'), '/').$path;
    }
}
