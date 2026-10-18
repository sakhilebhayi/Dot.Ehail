<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notice about a ride changing hands or ending
 * without a completed trip: cancelled by the other party, driver backed out
 * and the ride is back in the queue, request expired. $type is the
 * machine-readable kind (ride_cancelled, ride_requeued, ride_expired).
 */
class RideNoticeNotification extends Notification
{
    public function __construct(
        public Ride $ride,
        public string $type,
        public string $title,
        public string $message,
    ) {}

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
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'ride_id' => $this->ride->id,
            'url' => route('rides.show', $this->ride),
        ];
    }
}
