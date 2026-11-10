<?php

namespace App\Domain\Rides;

use App\Domain\Rides\Models\RideEvent;
use App\Enums\RideActor;
use App\Models\Ride;

/**
 * Appends to a ride's timeline (ride_events). Callers hold the ride's lock
 * (RideStateMachine's transaction, or the dispatcher's lease), so the next
 * sequence number is computed safely; the unique (ride_id, sequence) index is
 * the backstop.
 */
class RideTimeline
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function append(
        Ride $ride,
        string $type,
        RideActor $actor,
        ?int $actorId = null,
        ?string $reasonCode = null,
        array $context = [],
        ?string $from = null,
        ?string $to = null,
        ?string $transitionKey = null,
    ): RideEvent {
        $sequence = (int) RideEvent::where('ride_id', $ride->id)->max('sequence') + 1;

        return RideEvent::create([
            'ride_id' => $ride->id,
            'sequence' => $sequence,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor->value,
            'actor_id' => $actorId,
            'reason_code' => $reasonCode,
            'context' => $context === [] ? null : $context,
            'transition_key' => $transitionKey,
            'occurred_at' => now(),
        ]);
    }

    public function hasKey(Ride $ride, string $transitionKey): bool
    {
        return RideEvent::where('ride_id', $ride->id)->where('transition_key', $transitionKey)->exists();
    }
}
