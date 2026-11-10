<?php

namespace App\Domain\Payments;

use App\Services\Payments\Paystack\PaystackAuthorization;

/**
 * What a gateway said. status: succeeded | pending | failed | awaiting_cash | unavailable.
 */
final readonly class GatewayResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const AWAITING_CASH = 'awaiting_cash';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $summary  safe fields only (no customer or card data)
     */
    public function __construct(
        public string $status,
        public ?string $providerId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $channel = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $summary = [],
        public ?PaystackAuthorization $authorization = null,
        public ?string $reference = null,
    ) {}

    public static function failed(string $code, string $message): self
    {
        return new self(self::FAILED, code: $code, message: $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(self::UNAVAILABLE, code: 'unavailable', message: $message);
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
