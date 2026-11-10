<?php

namespace App\Domain\Pricing\Data;

/**
 * A geo zone as pricing needs it: kind (airport | special | restricted | surge_eligible) and the fee code a rule
 * set's zone_fees map is keyed by.
 */
final readonly class ZoneRef
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $kind,
        public ?string $feeCode,
    ) {}
}
