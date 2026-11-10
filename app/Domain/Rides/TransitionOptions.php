<?php

namespace App\Domain\Rides;

use App\Models\User;
use App\Models\Vehicle;

/**
 * Everything a transition may need besides the ride, the target and the
 * actor. Immutable; build with named arguments.
 */
final class TransitionOptions
{
    /**
     * @param  string|null  $key  idempotency key: a transition already recorded with this key is not applied again
     * @param  string|null  $reason  reason code stored on the timeline (and cancel_reason for cancellations)
     * @param  User|null  $driver  the driver being assigned (operator assignment; for a driver actor it is the actor)
     * @param  bool  $pinVerified  PinService checked the PIN before the transition
     * @param  int|null  $finalFareMinor  fare entered by an operator, minor units
     * @param  float|null  $finalFareDecimal  same, legacy decimal column (operator completion before R-46)
     * @param  array<string, mixed>  $context  extra context stored on the timeline row
     * @param  array<string, mixed>  $attributes  extra ride attributes written with the transition (caller-validated)
     * @param  bool  $force  operator/system override of the lead-time guard
     */
    public function __construct(
        public readonly ?string $key = null,
        public readonly ?string $reason = null,
        public readonly ?string $note = null,
        public readonly ?User $driver = null,
        public readonly ?Vehicle $vehicle = null,
        public readonly bool $pinVerified = false,
        public readonly ?int $finalFareMinor = null,
        public readonly ?float $finalFareDecimal = null,
        public readonly array $context = [],
        public readonly array $attributes = [],
        public readonly bool $force = false,
    ) {}
}
