<?php

namespace App\Services\Ehail;

use App\Enums\RideStatus;
use RuntimeException;

/**
 * A ride was asked to do something its current state does not allow. The
 * message is written for the person who clicked, so controllers surface it
 * as a validation error as-is.
 */
class InvalidRideTransition extends RuntimeException
{
    public static function between(RideStatus $from, RideStatus $to): self
    {
        return new self('That status change is not allowed.');
    }
}
