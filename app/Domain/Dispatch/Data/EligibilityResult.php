<?php

namespace App\Domain\Dispatch\Data;

final class EligibilityResult
{
    /**
     * @param  list<string>  $reasons  machine codes, e.g. profile_not_approved, vehicle_inactive
     * @param  list<string>  $messages  the same, written for the driver
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $reasons = [],
        public readonly array $messages = [],
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, string>  $reasonToMessage
     */
    public static function deny(array $reasonToMessage): self
    {
        return new self(false, array_keys($reasonToMessage), array_values($reasonToMessage));
    }
}
