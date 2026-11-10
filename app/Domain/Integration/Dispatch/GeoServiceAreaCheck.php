<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\ServiceAreaCheck;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Zones\ZoneResolver;

/**
 * Service-area check over geo's ZoneResolver. With no active service area configured the answer is "unknown"
 * (null), the same rule pricing applies (D-705): nothing is filtered until the operator defines areas.
 */
class GeoServiceAreaCheck implements ServiceAreaCheck
{
    public function __construct(private readonly ZoneResolver $zones) {}

    public function covers(float $lat, float $lng): ?bool
    {
        if (! ServiceArea::query()->where('is_active', true)->exists()) {
            return null;
        }

        return $this->zones->isServiced(new LatLng($lat, $lng));
    }
}
