<?php

namespace App\Domain\Health\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text health alert to the operations address (docs/R46_PERF.md section 6). Counts and states only.
 */
class HealthAlertMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public readonly string $status, public readonly array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Dot.Ehail health: '.$this->status);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e("Dot.Ehail health: {$this->status}\n\n".implode("\n", $this->lines)."\n\nRun `php artisan ehail:health` on the server for the full report.")));
    }
}
