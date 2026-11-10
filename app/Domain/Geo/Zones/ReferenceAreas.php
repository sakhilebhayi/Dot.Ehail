<?php

namespace App\Domain\Geo\Zones;

use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;

/**
 * South African reference shapes for the first markets. They are APPROXIMATE outlines drawn from public
 * coordinates of the metros and airports (city centres, suburbs at the edges, airport terminals and runways),
 * flagged is_approximate and to be reviewed by an operator before launch. Installing them only creates what is
 * missing: a shape an operator has already edited is never overwritten.
 */
class ReferenceAreas
{
    public const NOTE = 'Approximate outline from public coordinates; review before launch.';

    /**
     * @return list<array{slug: string, name: string, polygon: list<array{0: float, 1: float}>}>
     */
    public static function serviceAreas(): array
    {
        return [
            [
                'slug' => 'za-johannesburg',
                'name' => 'Greater Johannesburg (incl. OR Tambo)',
                'polygon' => [[-25.88, 27.88], [-25.88, 28.12], [-25.98, 28.22], [-26.05, 28.30], [-26.20, 28.30], [-26.30, 28.12],
                    [-26.50, 28.02], [-26.52, 27.85], [-26.38, 27.72], [-26.20, 27.70], [-26.05, 27.78], [-25.93, 27.82]],
            ],
            [
                'slug' => 'za-pretoria',
                'name' => 'Pretoria (Tshwane)',
                'polygon' => [[-25.45, 28.00], [-25.45, 28.35], [-25.62, 28.50], [-25.80, 28.50], [-25.93, 28.30], [-25.93, 28.05],
                    [-25.75, 27.95], [-25.55, 27.95]],
            ],
            [
                'slug' => 'za-cape-town',
                'name' => 'Cape Town',
                'polygon' => [[-33.47, 18.38], [-33.47, 18.55], [-33.70, 18.75], [-33.95, 18.95], [-34.15, 18.90], [-34.10, 18.60],
                    [-34.20, 18.48], [-34.36, 18.50], [-34.30, 18.38], [-34.05, 18.32], [-33.90, 18.36], [-33.70, 18.42]],
            ],
            [
                'slug' => 'za-durban',
                'name' => 'Durban (eThekwini, incl. King Shaka)',
                'polygon' => [[-29.50, 30.85], [-29.50, 31.25], [-29.85, 31.10], [-30.05, 30.95], [-30.30, 30.80], [-30.25, 30.65],
                    [-29.90, 30.60], [-29.70, 30.70]],
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, area: string, iata: string, polygon: list<array{0: float, 1: float}>}>
     */
    public static function airportZones(): array
    {
        return [
            ['slug' => 'za-jnb-airport', 'name' => 'O. R. Tambo International Airport', 'area' => 'za-johannesburg', 'iata' => 'JNB',
                'polygon' => [[-26.105, 28.215], [-26.105, 28.262], [-26.165, 28.262], [-26.165, 28.215]]],
            ['slug' => 'za-cpt-airport', 'name' => 'Cape Town International Airport', 'area' => 'za-cape-town', 'iata' => 'CPT',
                'polygon' => [[-33.955, 18.580], [-33.955, 18.630], [-33.990, 18.630], [-33.990, 18.580]]],
            ['slug' => 'za-dur-airport', 'name' => 'King Shaka International Airport', 'area' => 'za-durban', 'iata' => 'DUR',
                'polygon' => [[-29.595, 31.100], [-29.595, 31.135], [-29.635, 31.135], [-29.635, 31.100]]],
        ];
    }

    /**
     * @return array{areas_created: int, zones_created: int}
     */
    public static function install(): array
    {
        $areasCreated = 0;
        $zonesCreated = 0;

        foreach (self::serviceAreas() as $area) {
            $model = ServiceArea::query()->firstOrCreate(['slug' => $area['slug']], [
                'name' => $area['name'],
                'country_code' => 'ZA',
                'currency' => 'ZAR',
                'timezone' => 'Africa/Johannesburg',
                'polygon' => $area['polygon'],
                'is_active' => true,
                'is_approximate' => true,
                'source_note' => self::NOTE,
            ]);
            $areasCreated += $model->wasRecentlyCreated ? 1 : 0;
        }

        foreach (self::airportZones() as $zone) {
            $model = Zone::query()->firstOrCreate(['slug' => $zone['slug']], [
                'service_area_id' => ServiceArea::query()->where('slug', $zone['area'])->value('id'),
                'name' => $zone['name'],
                'kind' => 'airport',
                'polygon' => $zone['polygon'],
                'fee_code' => 'airport',
                'meta' => ['iata' => $zone['iata']],
                'is_active' => true,
                'is_approximate' => true,
                'source_note' => self::NOTE,
            ]);
            $zonesCreated += $model->wasRecentlyCreated ? 1 : 0;
        }

        return ['areas_created' => $areasCreated, 'zones_created' => $zonesCreated];
    }
}
