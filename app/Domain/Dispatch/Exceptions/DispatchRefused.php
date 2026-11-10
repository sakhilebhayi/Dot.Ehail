<?php

namespace App\Domain\Dispatch\Exceptions;

use RuntimeException;

/**
 * A dispatch/availability/request action refused for a reason the user can
 * act on. $reason is a stable machine code; the message is for people.
 */
class DispatchRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
