<?php

namespace App\Domain\Comms;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Dot.Notify and comms' own mail must never both email the same event. Rule: when Dot.Notify is
 * configured, it owns the email for every event in its catalogue; comms' own mail sends only the
 * events Notify does not carry. A source notification declares its Notify event through
 * toNotifyEvent() (non-null = Notify sends it); comms-originated types list theirs in
 * config('ehail_comms.notify_handled_types') because another part of the app already sends that
 * Notify event (driver assigned: RideNotices -> ride.accepted).
 */
final class NotifyPrecedence
{
    public static function handledByNotify(string $type, User $user, ?Notification $source): bool
    {
        if ($source !== null && method_exists($source, 'toNotifyEvent')) {
            try {
                return $source->toNotifyEvent($user) !== null;
            } catch (Throwable) {
                return false;
            }
        }

        return isset(config('ehail_comms.notify_handled_types', [])[$type]);
    }
}
