<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;

final class NoZoneLookup implements PricingZoneLookup
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function serviceAreaFor(GeoPoint $point): ?ServiceAreaRef
    {
        return null;
    }

    public function serviceArea(int $id): ?ServiceAreaRef
    {
        return null;
    }

    public function zonesFor(GeoPoint $point): array
    {
        return [];
    }
}
