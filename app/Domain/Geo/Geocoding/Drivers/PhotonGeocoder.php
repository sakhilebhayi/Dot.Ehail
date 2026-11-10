<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

/**
 * Photon (komoot) on an operator-run or contracted server (EHAIL_PHOTON_URL). Built for search-as-you-type.
 * The public photon.komoot.io instance is "fair use, extensive usage will be throttled" and is not a default.
 */
class PhotonGeocoder extends HttpGeocodingDriver
{
    public function name(): string
    {
        return 'photon';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.photon.url'));
    }

    public function supportsAutocomplete(): bool
    {
        return true;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        $params = ['q' => $query, 'limit' => $limit * 2, 'lang' => $language];

        if ($near !== null) {
            $params['lat'] = $near->lat;
            $params['lon'] = $near->lng;
        }

        $features = $this->send(fn ($http) => $http->get($this->url('/api'), $params))->json('features');

        return collect(is_array($features) ? $features : [])
            ->filter(fn ($f) => strtoupper((string) data_get($f, 'properties.countrycode')) === strtoupper($country))
            ->map(fn ($f) => $this->place($f))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        $feature = $this->send(fn ($http) => $http->get($this->url('/reverse'), [
            'lat' => $point->lat,
            'lon' => $point->lng,
            'limit' => 1,
            'lang' => $language,
        ]))->json('features.0');

        return is_array($feature) ? $this->place($feature) : null;
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function place(mixed $feature): ?Place
    {
        $coordinates = data_get($feature, 'geometry.coordinates');
        $position = is_array($coordinates) ? $this->point($coordinates[1] ?? null, $coordinates[0] ?? null) : null;
        $p = (array) data_get($feature, 'properties', []);

        if ($position === null) {
            return null;
        }

        $street = trim(trim((string) ($p['housenumber'] ?? '')).' '.trim((string) ($p['street'] ?? '')));
        $name = trim((string) ($p['name'] ?? '')) ?: $street;
        $parts = array_values(array_unique(array_filter([
            $name, $street !== $name ? $street : null, $p['district'] ?? null, $p['city'] ?? null, $p['state'] ?? null, $p['postcode'] ?? null,
        ])));

        if ($parts === []) {
            return null;
        }

        $ref = isset($p['osm_type'], $p['osm_id']) ? $p['osm_type'].$p['osm_id'] : null;

        return new Place(implode(', ', $parts), (string) $parts[0], $position, $this->name(), $ref);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ehail-geo.providers.photon.url'), '/').$path;
    }
}
