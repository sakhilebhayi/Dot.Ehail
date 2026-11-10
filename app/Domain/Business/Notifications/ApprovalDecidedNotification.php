<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\BusinessApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the requester the approver's answer. */
class ApprovalDecidedNotification extends Notification
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
        $message = (new MailMessage)->subject($a->status === BusinessApproval::APPROVED ? 'Your business ride was approved' : 'Your business ride was declined');

        if ($a->status === BusinessApproval::APPROVED) {
            $message->line('Book it before '.$a->use_by?->setTimezone(config('ehail_business.timezone'))->format('H:i').'. Get a fresh fare and choose the business profile; the approval is used for that ride.');
        } else {
            $message->line('The approver declined this ride.');
        }

        if ($a->decision_note) {
            $message->line('Note: '.$a->decision_note);
        }

        return $message;
    }
}
