<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\NotifyChannel;
use App\Services\Notify\NotifyClient;

/**
 * For a notification that also becomes a Dot.Notify email. Add `...$this->notifyChannel()` to
 * via() (it adds nothing until NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET are set) and implement
 *
 *     toNotifyEvent(object $notifiable): ?array{event: string, subject: string|int, bucket: string|int, data: array}
 *
 * returning null to send nothing.
 */
trait SendsNotifyEvent
{
    /** @return array<int, class-string> */
    protected function notifyChannel(): array
    {
        return app(NotifyClient::class)->enabled() ? [NotifyChannel::class] : [];
    }
}
