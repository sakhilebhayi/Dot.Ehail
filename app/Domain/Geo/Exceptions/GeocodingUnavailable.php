<?php

namespace App\Domain\Geo\Exceptions;

use RuntimeException;

/**
 * reason: not_configured | busy | timeout | provider_error | circuit_open | invalid_response
 */
class GeocodingUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $provider, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::describe($reason));
    }

    public static function describe(string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'Address search is unavailable: the operator has not configured a geocoding provider. Place the pin on the map instead.',
            'busy' => 'Address search is busy. Wait a second and try again.',
            'circuit_open' => 'Address search is temporarily unavailable. Place the pin on the map instead.',
            'timeout' => 'Address search did not answer in time. Try again.',
            default => 'Address search failed. Try again or place the pin on the map.',
        };
    }
}
