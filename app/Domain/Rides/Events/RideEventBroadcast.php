<?php

namespace App\Domain\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The broadcastable variant of every ride domain event, for hosts that can
 * run Reverb (config ehail_dispatch.broadcast_events). Same private channel
 * as RideStatusUpdated (ride.{id}, authorised to the passenger and driver in
 * routes/channels.php); the payload carries ids and states only, never
 * locations or names. Polling (RideFeed) stays the source of truth.
 */
final class RideEventBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly RideDomainEvent $event) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ride.'.$this->event->rideId)];
    }

    public function broadcastAs(): string
    {
        return $this->event::name();
    }

    public function broadcastWith(): array
    {
        return $this->event->payload();
    }
}
