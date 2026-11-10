<?php

namespace Tests\Doubles\Pricing;

use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Data\ZoneRef;

/**
 * Test double for the geo zone seam. Areas and zones are circles (centre + radius metres).
 */
final class FakeZoneLookup implements PricingZoneLookup
{
    /** @var list<array{ServiceAreaRef, GeoPoint, float}> */
    private array $areas = [];

    /** @var list<array{ZoneRef, GeoPoint, float}> */
    private array $zones = [];

    public function addArea(ServiceAreaRef $area, GeoPoint $centre, float $radiusM): self
    {
        $this->areas[] = [$area, $centre, $radiusM];

        return $this;
    }

    public function addZone(ZoneRef $zone, GeoPoint $centre, float $radiusM): self
    {
        $this->zones[] = [$zone, $centre, $radiusM];

        return $this;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function serviceAreaFor(GeoPoint $point): ?ServiceAreaRef
    {
        foreach ($this->areas as [$area, $centre, $radius]) {
            if ($centre->metresTo($point) <= $radius) {
                return $area;
            }
        }

        return null;
    }

    public function serviceArea(int $id): ?ServiceAreaRef
    {
        foreach ($this->areas as [$area]) {
            if ($area->id === $id) {
                return $area;
            }
        }

        return null;
    }

    public function zonesFor(GeoPoint $point): array
    {
        $found = [];

        foreach ($this->zones as [$zone, $centre, $radius]) {
            if ($centre->metresTo($point) <= $radius) {
                $found[] = $zone;
            }
        }

        return $found;
    }
}
