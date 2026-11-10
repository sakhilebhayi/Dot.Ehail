<?php

namespace App\Domain\Safety;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one email now (not queued: the queue only runs once a minute from cron) and says honestly
 * what happened. "sent" means the mail transport accepted it. When the configured mailer only
 * writes to the log or to memory (log, array), the result is "not_delivered" even though the
 * call succeeded, because nobody will receive it.
 */
class MailDelivery
{
    private const NON_DELIVERING = ['log', 'array'];

    /** @return array{status: string, error: ?string} */
    public function send(string $to, Mailable $mail): array
    {
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            Log::warning('Safety email failed.', ['mail' => class_basename($mail), 'error' => class_basename($e)]);

            return ['status' => 'failed', 'error' => 'mail_error:'.class_basename($e)];
        }

        $transport = $this->transport();

        if (in_array($transport, self::NON_DELIVERING, true)) {
            return ['status' => 'not_delivered', 'error' => 'mail_transport_'.$transport];
        }

        return ['status' => 'sent', 'error' => null];
    }

    public function transport(): string
    {
        $mailer = (string) config('mail.default');

        return (string) config("mail.mailers.{$mailer}.transport", $mailer);
    }
}
