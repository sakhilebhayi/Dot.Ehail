<?php

namespace App\Domain\Rides\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB written by the money agent from the contract (section 2). Dispatch owns it; reconcile at merge.
 * $actor is the role that cancelled: passenger | driver | operator | system.
 */
class RideCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly int $rideId,
        public readonly ?int $actorId,
        public readonly CarbonInterface $occurredAt,
        public readonly ?string $reasonCode = null,
        public readonly string $actor = 'passenger',
    ) {}
}
