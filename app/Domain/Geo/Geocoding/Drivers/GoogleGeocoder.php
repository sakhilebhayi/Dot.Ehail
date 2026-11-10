<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

/**
 * Google Maps Platform: Places API (New) Text Search for search (returns coordinates in one call) and the
 * Geocoding API for reverse lookups. Server key only; results are not cached by default (Google terms).
 */
class GoogleGeocoder extends HttpGeocodingDriver
{
    public const SEARCH = 'https://places.googleapis.com/v1/places:searchText';

    public const REVERSE = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.google.key'));
    }

    public function supportsAutocomplete(): bool
    {
        return true;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        $body = [
            'textQuery' => $query,
            'regionCode' => strtoupper($country),
            'languageCode' => $language,
            'pageSize' => min($limit, 20),
        ];

        if ($near !== null) {
            $body['locationBias'] = ['circle' => ['center' => ['latitude' => $near->lat, 'longitude' => $near->lng], 'radius' => 50000.0]];
        }

        $places = $this->send(fn ($http) => $http
            ->withHeaders([
                'X-Goog-Api-Key' => (string) config('ehail-geo.providers.google.key'),
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
            ])
            ->post(self::SEARCH, $body))->json('places');

        return collect(is_array($places) ? $places : [])->map(function ($p): ?Place {
            $position = $this->point(data_get($p, 'location.latitude'), data_get($p, 'location.longitude'));
            $label = trim((string) data_get($p, 'formattedAddress', ''));
            $name = trim((string) data_get($p, 'displayName.text', ''));

            if ($position === null || ($label === '' && $name === '')) {
                return null;
            }

            return new Place($label !== '' ? $label : $name, $name !== '' ? $name : $label, $position, $this->name(), data_get($p, 'id'));
        })->filter()->values()->all();
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        $response = $this->send(fn ($http) => $http->get(self::REVERSE, [
            'latlng' => sprintf('%F,%F', $point->lat, $point->lng),
            'key' => (string) config('ehail-geo.providers.google.key'),
            'language' => $language,
        ]));

        $status = (string) $response->json('status');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            throw new GeocodingUnavailable($status === 'OVER_QUERY_LIMIT' ? 'busy' : 'provider_error', $this->name());
        }

        $row = $response->json('results.0');
        $position = $this->point(data_get($row, 'geometry.location.lat'), data_get($row, 'geometry.location.lng'));
        $label = trim((string) data_get($row, 'formatted_address', ''));

        if ($position === null || $label === '') {
            return null;
        }

        return new Place($label, (string) strtok($label, ','), $position, $this->name(), data_get($row, 'place_id'));
    }
}
