<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notice about a ride's fare: paid, or waived by an operator. It
 * deliberately has no Dot.Notify event: no payment event exists in Notify's catalogue and none is
 * invented here. $type is ride_paid or ride_fare_waived.
 */
class RidePaymentNotification extends Notification
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
