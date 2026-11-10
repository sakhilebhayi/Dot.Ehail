<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\FanOut;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Notifications\CommsNotification;
use App\Domain\Payments\Receipts\ReceiptIssuedNotification;
use App\Domain\Comms\Preferences;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings every in-app notification other domains write (ride notices, payments, safety, support,
 * verification) under the same rules as comms' own: one in_app delivery row, then FanOut makes
 * the push / email / SMS copies the person's choices allow. Also applies those choices to the
 * other domains' own email (the receipt email) and records what happened to it.
 * Never throws: the notification that triggered it has already been written.
 */
final class SourceNotificationListener
{
    /** Other domains' mail-only notifications and the comms type they stand for. */
    private const MAIL_TYPES = [
        ReceiptIssuedNotification::class => 'payment_receipt',
    ];

    public function __construct(
        private readonly FanOut $fanOut,
        private readonly CommsCategories $categories,
        private readonly Preferences $preferences,
    ) {}

    /** Gate: a person who switched email off for a category gets no email from other paths either. */
    public function sending(NotificationSending $event): ?bool
    {
        try {
            if (! $event->notifiable instanceof User || $event->notification instanceof CommsNotification) {
                return null;
            }

            // Dot.Notify events are gated in NotifyClient::queue (one place for every path).
            $type = $this->mailType($event->notification);

            if ($event->channel !== 'mail' || $type === null) {
                return null;
            }

            if ($this->preferences->allows($event->notifiable, $this->categories->categoryFor($type), 'email')) {
                return null;
            }

            $this->recordMail($event->notifiable, $event->notification, $type, NotificationDelivery::SKIPPED, 'preference_off');

            return false;
        } catch (Throwable $e) {
            Log::warning('Comms preference gate failed; sending as before.', ['error' => class_basename($e)]);

            return null;
        }
    }

    public function sent(NotificationSent $event): void
    {
        try {
            if (! $event->notifiable instanceof User || $event->notification instanceof CommsNotification) {
                return;
            }

            if ($event->channel === 'database' && $event->response instanceof DatabaseNotification) {
                $this->fanOutFromDatabase($event->notifiable, $event->response, $event->notification);

                return;
            }

            $type = $this->mailType($event->notification);

            if ($event->channel === 'mail' && $type !== null) {
                $mailer = (string) config('mail.default');
                $delivers = ! in_array($mailer, ['log', 'array'], true);
                $this->recordMail($event->notifiable, $event->notification, $type,
                    $delivers ? NotificationDelivery::SENT : NotificationDelivery::SKIPPED,
                    $delivers ? null : 'mail_transport_not_delivering', 'mail:'.$mailer,
                    is_object($event->response) && method_exists($event->response, 'getMessageId') ? (string) $event->response->getMessageId() : null);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not fan out a notification.', ['notification' => class_basename($event->notification), 'error' => class_basename($e)]);
        }
    }

    public function failed(NotificationFailed $event): void
    {
        try {
            $type = $this->mailType($event->notification);

            if ($event->notifiable instanceof User && $event->channel === 'mail' && $type !== null) {
                $this->recordMail($event->notifiable, $event->notification, $type, NotificationDelivery::FAILED, 'mail_error', 'mail:'.config('mail.default'));
            }
        } catch (Throwable) {
        }
    }

    private function fanOutFromDatabase(User $user, DatabaseNotification $record, object $notification): void
    {
        $data = (array) $record->data;
        $type = (string) ($data['type'] ?? $this->typeOf($notification, $user));
        $category = $this->categories->categoryFor($type);
        $eventKey = 'n:'.$record->id;
        $now = now();
        $collapse = isset($data['ride_id']) ? 'ride-'.(int) $data['ride_id'] : null;

        DB::table('notification_deliveries')->insertOrIgnore([
            'event_key' => $eventKey,
            'user_id' => $user->id,
            'channel' => NotificationDelivery::IN_APP,
            'category' => $category,
            'type' => $type,
            'notification_id' => $record->id,
            'status' => NotificationDelivery::SENT,
            'provider' => 'database',
            'provider_id' => $record->id,
            'collapse_key' => $collapse,
            'attempts' => 1,
            'last_attempt_at' => $now,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The other domains already address their notices to the other party (a passenger's
        // cancellation tells the driver). The one notice that also reaches the person who acted is
        // the completion notice: the driver completed the trip, so it is not pushed back to them.
        // (Not "whoever made the request": dispatch moves rides on during anyone's poll, so an
        // expiry can happen inside the passenger's own request without being their action.)
        $actorIsRecipient = $type === 'ride_completed' && isset($data['ride_id'])
            && (int) Ride::query()->whereKey((int) $data['ride_id'])->value('driver_id') === (int) $user->id;

        $this->fanOut->plan($user, (string) $record->id, $type, $eventKey, $collapse, $actorIsRecipient, $notification instanceof \Illuminate\Notifications\Notification ? $notification : null);
    }

    private function recordMail(User $user, object $notification, string $type, string $status, ?string $reason, ?string $provider = null, ?string $providerId = null): void
    {
        $now = now();
        $key = $notification instanceof ReceiptIssuedNotification ? 'receipt:'.$notification->receipt->id : 'mail:'.class_basename($notification).':'.($notification->id ?? sha1(spl_object_hash($notification)));

        DB::table('notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'user_id' => $user->id,
            'channel' => NotificationDelivery::EMAIL,
            'category' => $this->categories->categoryFor($type),
            'type' => $type,
            'status' => $status,
            'reason' => $reason,
            'provider' => $provider,
            'provider_id' => $providerId ? mb_substr($providerId, 0, 191) : null,
            'attempts' => $status === NotificationDelivery::SKIPPED && $reason === 'preference_off' ? 0 : 1,
            'last_attempt_at' => $now,
            'sent_at' => $status === NotificationDelivery::SENT ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function mailType(object $notification): ?string
    {
        return self::MAIL_TYPES[$notification::class] ?? null;
    }

    private function typeOf(object $notification, User $user): string
    {
        try {
            if (method_exists($notification, 'toDatabase')) {
                $data = (array) $notification->toDatabase($user);
                if (isset($data['type'])) {
                    return (string) $data['type'];
                }
            }
        } catch (Throwable) {
        }

        return 'general';
    }
}
