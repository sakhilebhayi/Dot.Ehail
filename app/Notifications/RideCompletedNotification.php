<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification sent when a ride reaches the
 * `completed` status. Not yet wired to any automatic trigger — dispatch
 * manually via `$user->notify(new RideCompletedNotification($ride))` until a
 * Ride lifecycle observer exists to emit it automatically (see wiki.md §5
 * and §8 — the planned `logistics.trip.completed` event has no listener yet).
 */
class RideCompletedNotification extends Notification
{
    public function __construct(public Ride $ride)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $fare = $this->ride->final_fare !== null
            ? 'R ' . number_format((float) $this->ride->final_fare, 2)
            : 'fare pending';

        return [
            'type'    => 'ride_completed',
            'title'   => 'Ride completed',
            'message' => "Ride #{$this->ride->id} from {$this->ride->pickup_address} to {$this->ride->dropoff_address} finished ({$fare}).",
            'ride_id' => $this->ride->id,
            'url'     => route('rides.show', $this->ride),
        ];
    }
}
