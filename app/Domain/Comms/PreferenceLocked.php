<?php

namespace App\Domain\Comms;

use RuntimeException;

/** A critical category's channels cannot be switched off. */
final class PreferenceLocked extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('Safety messages cannot be switched off.');
    }
}
