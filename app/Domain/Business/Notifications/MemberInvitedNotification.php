<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\BusinessMember;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invitation email; the link carries the one-time token (only its hash is stored). */
class MemberInvitedNotification extends Notification
{
    use Queueable;

    public function __construct(public BusinessMember $member, public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = $this->member->account;

        return (new MailMessage)
            ->subject('Join '.$account->displayName().' on Dot.Ehail')
            ->line($account->displayName().' added you to its Dot.Ehail business account as '.strtolower($this->member->roleLabel()).'.')
            ->line('Sign in with this email address ('.$this->member->email.') and accept the invitation. It expires on '.$this->member->invite_expires_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j F Y').'.')
            ->action('Accept the invitation', route('business.invitations.show', $this->token));
    }
}
