<?php

namespace App\Domain\Safety\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app message to a rider or driver from the safety domain: an operator acknowledged their
 * report, a check-in question is waiting, a report was reviewed.
 */
class SafetyUpdateNotification extends Notification
{
    /** @param array<string, scalar|null> $extra */
    public function __construct(public string $type, public string $title, public string $message, public array $extra = []) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'message' => $this->message] + $this->extra;
    }
}
