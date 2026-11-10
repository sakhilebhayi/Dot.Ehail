<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

/**
 * Nominatim (jsonv2). On the public OSMF server: at most 1 request per second for the whole application
 * (enforced by Geocoder), identified by User-Agent, results cached, OSM attribution shown, and NO autocomplete
 * (the policy forbids it), so the UI searches when the user submits.
 */
class NominatimGeocoder extends HttpGeocodingDriver
{
    public function name(): string
    {
        return 'nominatim';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.nominatim.url'));
    }

    public function supportsAutocomplete(): bool
    {
        return false;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'countrycodes' => strtolower($country),
            'limit' => $limit,
            'accept-language' => $language,
        ];

        if ($near !== null) {
            // A soft preference box of about 50 km around the user; results outside it still come back.
            $params['viewbox'] = sprintf('%F,%F,%F,%F', $near->lng - 0.5, $near->lat + 0.5, $near->lng + 0.5, $near->lat - 0.5);
            $params['bounded'] = 0;
        }

        $rows = $this->send(fn ($http) => $http->get($this->url('/search'), $params))->json();

        return collect(is_array($rows) ? $rows : [])
            ->map(fn ($row) => is_array($row) ? $this->place($row) : null)
            ->filter()
            ->values()
            ->all();
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        $row = $this->send(fn ($http) => $http->get($this->url('/reverse'), [
            'lat' => $point->lat,
            'lon' => $point->lng,
            'format' => 'jsonv2',
            'zoom' => 18,
            'accept-language' => $language,
        ]))->json();

        return is_array($row) && ! isset($row['error']) ? $this->place($row) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function place(array $row): ?Place
    {
        $position = $this->point($row['lat'] ?? null, $row['lon'] ?? null);
        $label = trim((string) ($row['display_name'] ?? ''));

        if ($position === null || $label === '') {
            return null;
        }

        $name = trim((string) ($row['name'] ?? '')) ?: strtok($label, ',');

        return new Place($label, (string) $name, $position, $this->name(), isset($row['place_id']) ? (string) $row['place_id'] : null);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ehail-geo.providers.nominatim.url'), '/').$path;
    }
}
