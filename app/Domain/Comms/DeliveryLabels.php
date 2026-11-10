<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\NotificationDelivery;

/** Plain words for a delivery's state; "sent" only when the channel accepted it. */
final class DeliveryLabels
{
    private const CHANNELS = ['in_app' => 'In the app', 'push' => 'Push', 'email' => 'Email', 'sms' => 'SMS'];

    private const REASONS = [
        'preference_off' => 'off in your settings',
        'own_action' => 'not sent: you did this yourself',
        'push_not_configured' => 'not set up on this service',
        'no_push_subscription' => 'not turned on for any device',
        'subscription_gone' => 'the device no longer accepts it',
        'no_email_address' => 'no email address',
        'sent_by_dot_notify' => 'handed to Dot.Notify',
        'mail_transport_not_delivering' => 'not delivered: email is only logged on this service',
        'receipt_email_sent_by_money' => 'sent with your receipt',
        'sms_not_configured' => 'SMS is not available',
        'no_confirmed_number' => 'no confirmed number',
        'superseded' => 'not sent: a newer update replaced it',
        'expired' => 'not sent: too late to be useful',
        'recipient_gone' => 'account no longer exists',
        'notification_gone' => 'the message was deleted',
    ];

    public static function channel(string $channel): string
    {
        return self::CHANNELS[$channel] ?? ucfirst($channel);
    }

    public static function for(NotificationDelivery $delivery): string
    {
        $channel = self::channel($delivery->channel);

        return match ($delivery->status) {
            NotificationDelivery::SENT => $delivery->channel === NotificationDelivery::IN_APP ? $channel : "{$channel}: sent",
            NotificationDelivery::QUEUED, NotificationDelivery::SENDING => $delivery->attempts > 0 ? "{$channel}: retrying" : "{$channel}: waiting to send",
            NotificationDelivery::FAILED => "{$channel}: failed".($delivery->reason ? ' ('.(self::REASONS[$delivery->reason] ?? str_replace('_', ' ', $delivery->reason)).')' : ''),
            default => "{$channel}: ".(self::REASONS[(string) $delivery->reason] ?? 'not sent'),
        };
    }
}
