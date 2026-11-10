<?php

namespace App\Domain\Pricing\Legacy;

use App\Domain\Pricing\Models\FareQuote;

/**
 * The legacy request form's estimate, produced by the R-46 engine: decimal strings for the old rides columns, the
 * signed quote to bind, or the reason there is no estimate.
 */
final readonly class LegacyEstimate
{
    public function __construct(
        public ?FareQuote $quote,
        public ?string $distanceKm,
        public ?string $estimatedFare,
        public ?string $unavailableReason,
    ) {}

    public static function none(?string $reason = null): self
    {
        return new self(null, null, null, $reason);
    }
}
