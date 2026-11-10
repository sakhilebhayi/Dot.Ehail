<?php

namespace App\Domain\Fleet\Exceptions;

use RuntimeException;

/**
 * A fleet action the rules do not allow right now, with a machine code and a sentence for the person.
 */
class FleetActionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
