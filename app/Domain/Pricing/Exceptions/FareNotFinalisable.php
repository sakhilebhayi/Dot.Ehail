<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * reason: no_snapshot (a ride created before R-46 pricing, or without a quote: keep its historical fare) |
 * not_finalised (an operator adjustment needs a final fare first) | invalid_adjustment
 */
class FareNotFinalisable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'This ride\'s fare cannot be changed here ('.$reason.').');
    }
}
