<?php

namespace App\Domain\Safety\Notifications;

use App\Domain\Safety\Models\SafetyIncident;
use App\Notifications\Concerns\SendsNotifyEvent;
use Illuminate\Notifications\Notification;

/**
 * Operations alert for a new, escalated or still-unacknowledged incident. In-app always; a
 * Dot.Notify email when Notify is configured (event safety.incident_alert). Carries the reference,
 * kind and severity only.
 */
class SafetyIncidentAlertNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public SafetyIncident $incident, public string $reason = 'raised') {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $title = match ($this->reason) {
            'escalated' => 'Escalated safety incident',
            'reminder' => 'Safety incident still unacknowledged',
            default => 'New safety incident',
        };

        return [
            'type' => 'safety_incident_alert',
            'title' => $title,
            'message' => "{$this->incident->reference}: {$this->incident->kind->label()} ({$this->incident->severity->value}).",
            'incident_id' => $this->incident->id,
            'reason' => $this->reason,
        ];
    }

    /**
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        return [
            'event' => 'safety.incident_alert',
            'subject' => $this->incident->id,
            'bucket' => $this->reason.'-'.$this->incident->escalation_level,
            'data' => [
                'reference' => $this->incident->reference,
                'kind' => $this->incident->kind->value,
                'severity' => $this->incident->severity->value,
                'reason' => $this->reason,
            ],
        ];
    }
}
