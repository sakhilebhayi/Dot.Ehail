<?php

namespace App\Domain\Verification\Notifications;

use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Notifications\Concerns\SendsNotifyEvent;
use Illuminate\Notifications\Notification;

/**
 * Tells the driver the outcome of a document review (in-app, plus Dot.Notify email when
 * configured). Carries the document type and the reason, never the document itself.
 */
class VerificationDocumentReviewedNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public VerificationDocument $document) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $label = (string) config('ehail_safety.markets.'.$this->document->market.'.document_types.'.$this->document->type.'.label', $this->document->type);

        [$title, $message] = match ($this->document->status) {
            DocumentStatus::Approved => ['Document approved', "{$label} was approved."],
            DocumentStatus::ResubmissionRequested => ['Please upload again', "{$label}: ".$this->reasonText()],
            default => ['Document rejected', "{$label}: ".$this->reasonText()],
        };

        return [
            'type' => 'verification_document_reviewed',
            'title' => $title,
            'message' => $message,
            'document_id' => $this->document->id,
            'status' => $this->document->status->value,
        ];
    }

    /**
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        return [
            'event' => 'driver.document_reviewed',
            'subject' => $this->document->id,
            'bucket' => $this->document->status->value,
            'data' => [
                'document_type' => $this->document->type,
                'decision' => $this->document->status->value,
                'reason' => $this->document->status === DocumentStatus::Approved ? '' : $this->reasonText(),
            ],
        ];
    }

    private function reasonText(): string
    {
        $reason = (string) config('ehail_safety.verification.reject_reasons.'.$this->document->reject_reason, '');

        return trim($reason.($this->document->review_note ? ' ('.mb_substr($this->document->review_note, 0, 300).')' : ''));
    }
}
