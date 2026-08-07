<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification sent when a ride reaches the
 * `completed` status. Dispatched automatically to the ride's passenger and
 * driver by `App\Observers\RideObserver` whenever `Ride.status` transitions
 * to `completed` (registered in `AppServiceProvider::boot()`). This is
 * separate from the outbound `logistics.trip.completed` ecosystem event
 * described in wiki.md §5/§8, which still has no publishing pipeline.
 */
class RideCompletedNotification extends Notification
{
    public function __construct(public Ride $ride) {}

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
            ? 'R '.number_format((float) $this->ride->final_fare, 2)
            : 'fare pending';

        return [
            'type' => 'ride_completed',
            'title' => 'Ride completed',
            'message' => "Ride #{$this->ride->id} from {$this->ride->pickup_address} to {$this->ride->dropoff_address} finished ({$fare}).",
            'ride_id' => $this->ride->id,
            'url' => route('rides.show', $this->ride),
        ];
    }
}
