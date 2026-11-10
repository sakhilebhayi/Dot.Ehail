<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\Geocoding\GeocodingDriver;
use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;

class NullGeocoder implements GeocodingDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supportsAutocomplete(): bool
    {
        return false;
    }

    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array
    {
        throw new GeocodingUnavailable('not_configured', 'none');
    }

    public function reverse(LatLng $point, string $language): ?Place
    {
        throw new GeocodingUnavailable('not_configured', 'none');
    }
}
