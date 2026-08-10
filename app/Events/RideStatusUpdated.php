<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to a ride's passenger and driver whenever its status changes,
 * so whoever has the ride detail page open sees it live instead of only on
 * a manual refresh. Fired from RideObserver::updated() on any status
 * transition (not just "completed", which is all that observer previously
 * watched for -- see RideCompletedNotification for that separate, existing
 * in-app notification).
 *
 * Payload is deliberately minimal: id, the new status, and a timestamp --
 * no addresses or passenger/driver names, since a ride's channel
 * authorization (see BroadcastServiceProvider) already restricts who
 * receives this, but there's no reason to broadcast more than the UI
 * actually needs to react (a status badge + "reload" prompt).
 */
class RideStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Ride $ride) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ride.'.$this->ride->id)];
    }

    public function broadcastAs(): string
    {
        return 'status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->ride->id,
            'status' => $this->ride->status,
            'updated_at' => $this->ride->updated_at?->toIso8601String(),
        ];
    }
}
