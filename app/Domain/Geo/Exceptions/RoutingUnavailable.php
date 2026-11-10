<?php

namespace App\Domain\Geo\Exceptions;

use RuntimeException;

/**
 * No road route could be produced. Callers must handle it: pricing refuses a route-based quote, the UI says why.
 * reason: not_configured | timeout | provider_error | circuit_open | no_route | invalid_response
 */
class RoutingUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $provider, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::describe($reason));
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'none');
    }

    public static function describe(string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'Route pricing is unavailable: the operator has not configured a routing provider.',
            'no_route' => 'No road route was found between these points.',
            'circuit_open' => 'Route pricing is temporarily unavailable: the routing provider failed repeatedly. Try again in a minute.',
            'timeout' => 'Route pricing is temporarily unavailable: the routing provider did not answer in time.',
            default => 'Route pricing is temporarily unavailable: the routing provider returned an error.',
        };
    }
}
