<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\BusinessStatement;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monthly statement to the billing email, and the overdue reminder (same message, different subject). */
class StatementIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(public BusinessStatement $statement, public bool $overdue = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->statement;
        $amount = MoneyFormatter::format($s->closing_minor, $s->currency);
        $message = (new MailMessage)->subject(($this->overdue ? 'Overdue: ' : '').'Dot.Ehail statement '.$s->number);

        if ($this->overdue) {
            $message->line("Statement {$s->number} ({$amount}) was due on ".$s->due_at->format('j F Y').' and is not paid yet. New rides on the account pause '.($s->account->grace_days).' days after the due date; trips already booked are not affected.');
        } else {
            $message->line("Your statement for {$s->snapshot['period_label']} is ready: {$amount}.")
                ->line($s->billing_mode === 'card' ? 'It is charged to the company card on file.' : 'Please pay by EFT by '.$s->due_at->format('j F Y').', quoting '.$s->number.'.');
        }

        return $message->action('View the statement', route('business.statements.show', $s));
    }
}
