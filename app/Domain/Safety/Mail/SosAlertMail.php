<?php

namespace App\Domain\Safety\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a trusted contact that someone pressed SOS (or asked for help). States plainly that
 * Dot.Ehail does not dispatch emergency services and lists the numbers to call.
 *
 * @param  list<array{label: string, number: string}>  $emergencyNumbers
 */
class SosAlertMail extends Mailable
{
    use Queueable;

    /** @param list<array{key: string, label: string, number: string}> $emergencyNumbers */
    public function __construct(
        public string $contactName,
        public string $personFirstName,
        public string $reference,
        public string $raisedAt,
        public ?string $shareUrl,
        public ?string $vehicle,
        public ?string $locationText,
        public array $emergencyNumbers,
        public ?string $optOutUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Safety alert: {$this->personFirstName} asked for help ({$this->reference})");
    }

    public function content(): Content
    {
        return new Content(text: 'safety.mail.sos-alert');
    }
}
