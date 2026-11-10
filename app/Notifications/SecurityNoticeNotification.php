<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A security notice to the account holder, by email and in the app: a payout bank account was added or changed,
 * a sign-in from a new browser. Email matters most: whoever holds a stolen session controls the app, not the
 * mailbox. The text carries no account number (last four digits at most) and no location. R-46 security S-15/S-27.
 */
class SecurityNoticeNotification extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $message,
        public ?string $url = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Dot.Ehail security notice: '.$this->title)
            ->line($this->message)
            ->line('If this was you, there is nothing to do. If it was not, sign in, change your password at InfoDot, and contact support straight away.');

        return $this->url !== null ? $mail->action('Review your account', $this->url) : $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'security_'.$this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
