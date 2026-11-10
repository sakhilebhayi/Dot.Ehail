<?php

namespace App\Domain\Pricing\Quotes;

/**
 * A typed "no price" result the UI must show as it is. reason codes:
 * outside_service_area | restricted_zone | routing_unavailable | category_unknown | category_not_offered |
 * no_tariff | no_supply | currency_unsupported
 */
final readonly class QuoteUnavailable
{
    public function __construct(
        public string $reason,
        public string $message,
        public ?string $categoryKey = null,
        public ?string $detail = null,
    ) {}

    public static function routing(string $providerReason, string $message): self
    {
        return new self('routing_unavailable', $message, null, $providerReason);
    }

    /**
     * @return array{available: false, reason: string, message: string, category: ?string, detail: ?string}
     */
    public function toArray(): array
    {
        return ['available' => false, 'reason' => $this->reason, 'message' => $this->message, 'category' => $this->categoryKey, 'detail' => $this->detail];
    }
}
