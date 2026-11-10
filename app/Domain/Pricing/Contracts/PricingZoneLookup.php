<?php

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Data\ZoneRef;

/**
 * The geo zone seam (ZoneResolver). When it is not configured (geo not merged) pricing falls back to the
 * country default market from config/ehail_pricing.php; when it is, a point outside every service area cannot be
 * quoted.
 */
interface PricingZoneLookup
{
    public function isConfigured(): bool;

    public function serviceAreaFor(GeoPoint $point): ?ServiceAreaRef;

    public function serviceArea(int $id): ?ServiceAreaRef;

    /**
     * @return list<ZoneRef>
     */
    public function zonesFor(GeoPoint $point): array;
}
