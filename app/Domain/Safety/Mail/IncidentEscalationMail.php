<?php

namespace App\Domain\Safety\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To an on-call address: an incident has not been acknowledged in time. No personal details. */
class IncidentEscalationMail extends Mailable
{
    use Queueable;

    public function __construct(public string $reference, public string $kind, public string $severity, public int $level, public string $levelLabel, public int $minutesOpen, public string $opsUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Escalation L{$this->level}] Unacknowledged {$this->severity} safety incident {$this->reference}");
    }

    public function content(): Content
    {
        return new Content(text: 'safety.mail.incident-escalation');
    }
}
