<?php

namespace App\Domain\Geo\Geocoding;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\LatLng;

interface GeocodingDriver
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Whether the provider's terms allow search-as-you-type. When false the UI searches on submit only.
     */
    public function supportsAutocomplete(): bool;

    /**
     * @return list<Place>
     *
     * @throws GeocodingUnavailable
     */
    public function search(string $query, ?LatLng $near, string $country, int $limit, string $language): array;

    /**
     * @throws GeocodingUnavailable
     */
    public function reverse(LatLng $point, string $language): ?Place;
}
