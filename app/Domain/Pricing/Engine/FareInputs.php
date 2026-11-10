<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Data\ZoneRef;

/**
 * Everything the formula needs besides the rule set. Distance is billable ROAD metres; duration is seconds.
 */
final readonly class FareInputs
{
    /**
     * @param  list<ZoneRef>  $pickupZones
     * @param  list<ZoneRef>  $dropoffZones
     * @param  ?int  $tollsMinor  null = unknown
     */
    public function __construct(
        public int $distanceM,
        public int $durationS,
        public array $pickupZones = [],
        public array $dropoffZones = [],
        public int $demandMultiplierBp = 10_000,
        public ?int $tollsMinor = null,
        public bool $tollsAreActual = false,
        public int $waitingSeconds = 0,
        public ?DiscountTerms $discount = null,
    ) {}
}
