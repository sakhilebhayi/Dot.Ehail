<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Data\ZoneRef;

/**
 * Adapts the geo domain's ZoneResolver and ServiceArea model. Bound only when those classes exist.
 */
final class GeoZoneLookupBridge implements PricingZoneLookup
{
    public const RESOLVER = 'App\\Domain\\Geo\\Zones\\ZoneResolver';

    public const SERVICE_AREA = 'App\\Domain\\Geo\\Models\\ServiceArea';

    public function __construct(private object $resolver) {}

    public static function available(): bool
    {
        return class_exists(self::RESOLVER) && class_exists(self::SERVICE_AREA) && class_exists(GeoRoutePlannerBridge::LAT_LNG);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function serviceAreaFor(GeoPoint $point): ?ServiceAreaRef
    {
        $area = $this->resolver->serviceAreaFor($this->latLng($point));

        return $area === null ? null : $this->ref($area);
    }

    public function serviceArea(int $id): ?ServiceAreaRef
    {
        $model = self::SERVICE_AREA;
        $area = $model::query()->find($id);

        return $area === null ? null : $this->ref($area);
    }

    public function zonesFor(GeoPoint $point): array
    {
        return array_values(array_map(
            fn (object $zone) => new ZoneRef((int) $zone->id, (string) $zone->slug, (string) $zone->kind, $zone->fee_code !== null ? (string) $zone->fee_code : null),
            $this->resolver->zonesFor($this->latLng($point)),
        ));
    }

    private function latLng(GeoPoint $point): object
    {
        $class = GeoRoutePlannerBridge::LAT_LNG;

        return new $class($point->lat, $point->lng);
    }

    private function ref(object $area): ServiceAreaRef
    {
        return new ServiceAreaRef((int) $area->id, (string) $area->slug, (string) $area->country_code, (string) $area->currency, (string) $area->timezone);
    }
}
