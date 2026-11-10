<?php

namespace App\Domain\Rides\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB written by the money agent from the contract (docs/R46_ARCHITECTURE.md section 2: payload
 * ride id + actor id + occurred_at + reason code). The dispatch domain owns this event; at merge keep
 * dispatch's class and make sure it exposes these four properties (money's listener reads them).
 */
class TripCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $rideId,
        public readonly ?int $actorId,
        public readonly CarbonInterface $occurredAt,
        public readonly ?string $reasonCode = null,
    ) {}
}
