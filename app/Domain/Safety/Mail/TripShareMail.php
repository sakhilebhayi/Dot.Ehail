<?php

namespace App\Domain\Safety\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sends a trip-share link to a confirmed trusted contact. */
class TripShareMail extends Mailable
{
    use Queueable;

    public function __construct(public string $contactName, public string $personFirstName, public string $shareUrl, public string $expiresAt, public ?string $optOutUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->personFirstName} shared a trip with you");
    }

    public function content(): Content
    {
        return new Content(text: 'safety.mail.trip-share');
    }
}
