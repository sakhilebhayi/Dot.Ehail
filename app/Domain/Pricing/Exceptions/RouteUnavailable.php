<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * No road route, so no route-based fare. reason: not_configured | timeout | provider_error | circuit_open | no_route
 */
class RouteUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : match ($reason) {
            'not_configured' => 'Fares cannot be calculated yet: no routing provider is configured.',
            'no_route' => 'No road route was found between these points.',
            default => 'Fares are temporarily unavailable because the routing service did not answer. Try again in a minute.',
        });
    }
}
