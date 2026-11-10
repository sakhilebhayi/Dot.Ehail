<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Pricing\Data\GeoPoint;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * What a passenger (or an anonymous visitor, for estimates) asks to be priced. There is deliberately no amount in
 * here: prices are computed on the server, never accepted from a client.
 */
final readonly class QuoteRequest
{
    public function __construct(
        public GeoPoint $pickup,
        public GeoPoint $dropoff,
        public ?string $categoryKey = null,
        public ?User $passenger = null,
        public ?CarbonInterface $at = null,
        public ?string $promoCode = null,
        public string $purpose = 'booking',
    ) {}

    public function withCategory(string $key): self
    {
        return new self($this->pickup, $this->dropoff, $key, $this->passenger, $this->at, $this->promoCode, $this->purpose);
    }
}
