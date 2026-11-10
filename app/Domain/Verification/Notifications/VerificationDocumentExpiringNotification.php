<?php

namespace App\Domain\Verification\Notifications;

use App\Domain\Verification\Models\VerificationDocument;
use App\Notifications\Concerns\SendsNotifyEvent;
use Illuminate\Notifications\Notification;

/**
 * Expiry warning ($days > 0) or "it has expired" ($days === 0) for an approved document.
 */
class VerificationDocumentExpiringNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public VerificationDocument $document, public int $days) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $label = (string) config('ehail_safety.markets.'.$this->document->market.'.document_types.'.$this->document->type.'.label', $this->document->type);
        $date = $this->document->expires_on?->toDateString();

        return [
            'type' => $this->days === 0 ? 'verification_document_expired' : 'verification_document_expiring',
            'title' => $this->days === 0 ? 'Document expired' : 'Document expires soon',
            'message' => $this->days === 0
                ? "{$label} expired on {$date}. Upload the current one to keep driving."
                : "{$label} expires on {$date}. Upload the renewed one before then.",
            'document_id' => $this->document->id,
        ];
    }

    /**
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        return [
            'event' => $this->days === 0 ? 'driver.document_expired' : 'driver.document_expiring',
            'subject' => $this->document->id,
            'bucket' => 'd'.$this->days,
            'data' => [
                'document_type' => $this->document->type,
                'expires_on' => (string) $this->document->expires_on?->toDateString(),
            ],
        ];
    }
}
