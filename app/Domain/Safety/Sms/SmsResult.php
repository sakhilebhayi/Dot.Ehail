<?php

namespace App\Domain\Safety\Sms;

final readonly class SmsResult
{
    private function __construct(
        public string $status,
        public ?string $providerMessageId = null,
        public ?string $error = null,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self('sent', $providerMessageId);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, mb_substr($error, 0, 120));
    }

    public static function notConfigured(): self
    {
        return new self('skipped', null, 'sms_not_configured');
    }

    public function wasSent(): bool
    {
        return $this->status === 'sent';
    }
}
