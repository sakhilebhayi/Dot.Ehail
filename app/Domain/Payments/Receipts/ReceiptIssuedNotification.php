<?php

namespace App\Domain\Payments\Receipts;

use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the receipt (link to the print-friendly page, which is the receipt of record).
 */
class ReceiptIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(public Receipt $receipt) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->receipt->snapshot;
        $message = (new MailMessage)
            ->subject('Your receipt '.$this->receipt->number)
            ->greeting('Thank you for riding')
            ->line(($s['kind'] ?? 'ride') === 'ride'
                ? 'From '.($s['pickup_address'] ?? '').' to '.($s['dropoff_address'] ?? '').'.'
                : 'Cancellation fee for ride #'.($s['ride_id'] ?? ''));

        foreach ((array) ($s['components'] ?? []) as $line) {
            $message->line($line['label'].': '.Money::format((int) $line['amount_minor'], $this->receipt->currency));
        }

        if (($s['promotion_minor'] ?? 0) > 0) {
            $message->line('Promotion: -'.Money::format((int) $s['promotion_minor'], $this->receipt->currency));
        }

        $message->line('Total paid: '.Money::format($this->receipt->total_minor, $this->receipt->currency).' ('.($s['payment_method'] ?? '').')');

        if (! empty($s['tax_label'])) {
            $message->line($s['tax_label'].': '.Money::format((int) $s['tax_minor'], $this->receipt->currency));
        }

        return $message->action('View or print the receipt', route('money.receipts.show', $this->receipt));
    }
}
