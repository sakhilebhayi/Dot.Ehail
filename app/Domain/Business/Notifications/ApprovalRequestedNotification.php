<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessApproval;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells approvers a ride is waiting for their decision (no addresses beyond what the approver needs). */
class ApprovalRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(public BusinessApproval $approval) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->approval;

        return (new MailMessage)
            ->subject('A business ride is waiting for your approval')
            ->line($a->riderLabel().' asks to ride on the company account: '.MoneyFormatter::format($a->estimate_minor, $a->currency).'.')
            ->line('From '.($a->pickup_address ?? 'the pickup').' to '.($a->dropoff_address ?? 'the drop-off').'.')
            ->line($a->purpose ? 'Purpose: '.$a->purpose : 'No purpose given.')
            ->line('Decide before '.$a->decide_by->setTimezone(BusinessClock::timezone())->format('H:i').'; after that the request expires.')
            ->action('Open the approvals queue', route('business.approvals'));
    }
}
