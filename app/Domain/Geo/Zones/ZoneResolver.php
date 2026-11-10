<?php

namespace App\Domain\Geo\Zones;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;

/**
 * Which market and which zones a point falls in. A bounding-box query narrows the candidates, then ray casting
 * decides. The pricing domain reads Zone::fee_code; dispatch reads ServiceArea for currency and timezone.
 */
class ZoneResolver
{
    /**
     * The active service area containing the point; the smallest one wins when areas overlap.
     */
    public function serviceAreaFor(LatLng $point): ?ServiceArea
    {
        return ServiceArea::query()
            ->where('is_active', true)
            ->boxContaining($point)
            ->get()
            ->filter(fn (ServiceArea $area) => $area->containsPoint($point))
            ->sortBy(fn (ServiceArea $area) => ($area->max_lat - $area->min_lat) * ($area->max_lng - $area->min_lng))
            ->first();
    }

    public function isServiced(LatLng $point): bool
    {
        return $this->serviceAreaFor($point) !== null;
    }

    /**
     * Every active zone containing the point, optionally of one kind.
     *
     * @return list<Zone>
     */
    public function zonesFor(LatLng $point, ?string $kind = null): array
    {
        return Zone::query()
            ->where('is_active', true)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->boxContaining($point)
            ->orderBy('id')
            ->get()
            ->filter(fn (Zone $zone) => $zone->containsPoint($point))
            ->values()
            ->all();
    }

    public function isRestricted(LatLng $point): bool
    {
        return $this->zonesFor($point, 'restricted') !== [];
    }
}
