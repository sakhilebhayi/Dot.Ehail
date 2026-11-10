<?php

namespace App\Domain\Safety\Sms;

/**
 * Sends one SMS. Implementations never throw: the result says what happened. isConfigured()
 * false means nothing is ever sent and callers must report SMS as "not configured".
 */
interface SmsGateway
{
    public function isConfigured(): bool;

    public function name(): string;

    /** @param string $to E.164, e.g. +27821234567 */
    public function send(string $to, string $message): SmsResult;
}
