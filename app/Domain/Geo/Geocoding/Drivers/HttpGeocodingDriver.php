<?php

namespace App\Domain\Geo\Geocoding\Drivers;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\Geocoding\GeocodingDriver;
use App\Domain\Geo\LatLng;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class HttpGeocodingDriver implements GeocodingDriver
{
    protected function http(): PendingRequest
    {
        $timeout = (float) config('ehail-geo.geocoding.timeout_seconds', 4);

        return Http::timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
            ->withUserAgent((string) config('ehail-geo.user_agent'))
            ->acceptJson();
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function send(Closure $send): Response
    {
        try {
            $response = $send($this->http());
        } catch (ConnectionException) {
            throw new GeocodingUnavailable('timeout', $this->name());
        }

        if ($response->status() === 429) {
            throw new GeocodingUnavailable('busy', $this->name());
        }

        if ($response->failed()) {
            throw new GeocodingUnavailable('provider_error', $this->name());
        }

        return $response;
    }

    protected function point(mixed $lat, mixed $lng): ?LatLng
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) ? new LatLng($lat, $lng) : null;
    }
}
