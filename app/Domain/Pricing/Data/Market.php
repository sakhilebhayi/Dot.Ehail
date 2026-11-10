<?php

namespace App\Domain\Pricing\Data;

/**
 * Where a price applies: country, currency, timezone and (when geo is wired) the service area.
 */
final readonly class Market
{
    public function __construct(
        public string $country,
        public string $currency,
        public string $timezone,
        public ?ServiceAreaRef $serviceArea = null,
    ) {}

    public function serviceAreaId(): ?int
    {
        return $this->serviceArea?->id;
    }

    public static function defaults(): self
    {
        return new self(
            (string) config('ehail_pricing.default_country'),
            (string) config('ehail_pricing.default_currency'),
            (string) config('ehail_pricing.default_timezone'),
        );
    }
}
