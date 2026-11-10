<?php

namespace App\Domain\Rides\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Base of the ride lifecycle domain events (docs/R46_ARCHITECTURE.md
 * section 2). Payload: ride id, actor id, occurred_at, reason code, plus the
 * from/to states. Dispatched only after the transition's transaction
 * commits, so a listener never reacts to a change that was rolled back.
 * Listeners load the ride themselves; the event carries ids, not models.
 */
abstract class RideDomainEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $rideId,
        public readonly ?int $actorId,
        public readonly string $actorType,
        public readonly Carbon $occurredAt,
        public readonly ?string $reasonCode = null,
        public readonly ?string $fromStatus = null,
        public readonly ?string $toStatus = null,
    ) {}

    /**
     * Short machine name, also the broadcast name suffix.
     */
    abstract public static function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => static::name(),
            'ride_id' => $this->rideId,
            'actor_id' => $this->actorId,
            'actor_type' => $this->actorType,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'reason_code' => $this->reasonCode,
            'from' => $this->fromStatus,
            'to' => $this->toStatus,
        ];
    }
}
