<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notification channel that forwards what a notification already tells the user in-app to
 * Dot.Notify, so the same trigger and the same recipient also get an email. A notification
 * opts in with SendsNotifyEvent::toNotifyEvent(). Never throws: the database notification is
 * written before this channel runs, and nothing here can undo or delay it.
 */
class NotifyChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        try {
            if (! $notifiable instanceof User || ! method_exists($notification, 'toNotifyEvent')) {
                return;
            }

            $client = app(NotifyClient::class);

            if (! $client->enabled()) {
                return;
            }

            $event = $notification->toNotifyEvent($notifiable);

            if ($event === null) {
                return;
            }

            $client->queue($notifiable, $event['event'], $event['subject'], $event['bucket'], $event['data']);
        } catch (Throwable $e) {
            Log::warning('Could not hand a notification to Dot.Notify.', ['notification' => class_basename($notification), 'error' => class_basename($e)]);
        }
    }
}
