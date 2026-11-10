<?php

namespace App\Domain\Safety\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Asks a person to confirm they agree to be someone's trusted contact. Plain text. */
class TrustedContactConfirmationMail extends Mailable
{
    use Queueable;

    public function __construct(public string $contactName, public string $ownerFirstName, public string $confirmUrl, public string $optOutUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->ownerFirstName} added you as a trusted contact on Dot.Ehail");
    }

    public function content(): Content
    {
        return new Content(text: 'safety.mail.contact-confirmation');
    }
}
