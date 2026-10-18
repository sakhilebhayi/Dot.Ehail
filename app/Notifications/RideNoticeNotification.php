<?php

namespace App\Notifications;

use App\Models\Ride;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notice about a ride changing hands or ending
 * without a completed trip: cancelled by the other party, driver backed out
 * and the ride is back in the queue, request expired. $type is the
 * machine-readable kind (ride_cancelled, ride_requeued, ride_expired).
 */
class RideNoticeNotification extends Notification
{
    use SendsNotifyEvent;

    /**
     * @param  string|null  $cancelledByRole  passenger|driver|operator, for the Dot.Notify email only
     * @param  string|null  $reason  a fixed, human-readable reason (never the free-text note), for the email only
     */
    public function __construct(
        public Ride $ride,
        public string $type,
        public string $title,
        public string $message,
        public ?string $cancelledByRole = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /**
     * The email-worthy copy of this notice for Dot.Notify, same recipient as the in-app notice:
     * ride_cancelled and ride_requeued (the driver backed out) become ride.cancelled, ride_expired
     * becomes ride.expired. Nothing else (no addresses, notes, names or coordinates) is included.
     *
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}|null
     */
    public function toNotifyEvent(object $notifiable): ?array
    {
        $url = app(NotifyClient::class)->pageUrl('rides.show', $this->ride);

        return match ($this->type) {
            'ride_expired' => [
                'event' => 'ride.expired',
                'subject' => $this->ride->id,
                'bucket' => 'expired',
                'data' => ['url' => $url],
            ],
            'ride_cancelled', 'ride_requeued' => $this->cancelledByRole === null ? null : [
                'event' => 'ride.cancelled',
                'subject' => $this->ride->id,
                // A driver backing out can happen again after the ride is re-accepted: count it.
                'bucket' => $this->type === 'ride_requeued' ? 'driver-cancelled-'.(int) $this->ride->reassignment_count : 'cancelled',
                'data' => [
                    'cancelled_by_role' => $this->cancelledByRole,
                    'reason' => (string) $this->reason,
                    'url' => $url,
                ],
            ],
            default => null,
        };
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
