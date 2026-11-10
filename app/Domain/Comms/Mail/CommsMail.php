<?php

namespace App\Domain\Comms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The email copy of an in-app notification: the same title and words, a link, and why it came. */
class CommsMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $heading,
        public string $body,
        public ?string $actionUrl,
        public string $categoryLabel,
        public string $settingsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'comms.mail.notification');
    }
}
