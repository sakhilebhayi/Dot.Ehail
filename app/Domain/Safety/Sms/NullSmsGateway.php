<?php

namespace App\Domain\Safety\Sms;

/** The default: no SMS provider. Sends nothing and says so. */
class NullSmsGateway implements SmsGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function send(string $to, string $message): SmsResult
    {
        return SmsResult::notConfigured();
    }
}
