<?php

namespace App\Support\Ui;

use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Zones\ZoneResolver;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Words for a place that people read (R-46 polish, D-2201): a stored address label when there is one, otherwise a
 * name looked up from the coordinates, otherwise the zone or service area the point lies in. Raw coordinates are
 * never the words; screens that need them for emergency services show them apart, as a secondary detail.
 *
 * "Pinned location (lat, lng)" is the label the ride and quote domains store when the app sent no address
 * (PricingFareQuoteGateway::pinnedLabel): it is treated as no label.
 */
class PlaceNames
{
    public function __construct(private readonly ZoneResolver $zones) {}

    /** Shorthand for views: the stored label, or words for the point when the label is only a pinned point. */
    public static function for(?string $label, mixed $lat = null, mixed $lng = null, bool $lookup = false): string
    {
        if (! self::isPinned($label)) {
            return self::short((string) $label);
        }

        return app(self::class)->name(null, $lat !== null ? (float) $lat : null, $lng !== null ? (float) $lng : null, $lookup);
    }

    public static function isPinned(?string $label): bool
    {
        return $label === null || trim($label) === '' || preg_match('/^Pinned location \(/', trim($label)) === 1;
    }

    /**
     * A readable name for the point. $lookup allows one reverse-geocoding call (cached by the geocoder); leave it
     * off on pages that list many places or poll, and on any path where waiting on a provider is not acceptable.
     */
    public function name(?string $label, ?float $lat, ?float $lng, bool $lookup = false): string
    {
        if (! self::isPinned($label)) {
            return self::short((string) $label);
        }

        if ($lat === null || $lng === null) {
            return 'Place not recorded';
        }

        $point = new LatLng($lat, $lng);
        $key = self::memoKey($lat, $lng);

        // A name found once is remembered, so lists that may not call a provider still read as places.
        $known = rescue(fn () => Cache::get($key), null, false);
        if (is_string($known) && $known !== '') {
            return $known;
        }

        if ($lookup) {
            try {
                $place = app(Geocoder::class)->reverse($point);
                if ($place !== null && trim($place->label) !== '') {
                    $name = 'Near '.self::short($place->label);
                    rescue(fn () => Cache::put($key, $name, now()->addDays(30)), null, false);

                    return $name;
                }
            } catch (Throwable) {
                // Not configured, busy or down: fall through to the area, which needs no provider.
            }
        }

        return 'A pinned point '.$this->areaPhrase($point);
    }

    /** Rounded to about 11 m, so the key never holds a more exact position than the screen already shows. */
    private static function memoKey(float $lat, float $lng): string
    {
        return 'ehail:place-name:'.number_format($lat, 4, '.', '').','.number_format($lng, 4, '.', '');
    }

    /** "in Sandton (zone)" style phrase from the local zone and area tables; no provider involved. */
    public function areaPhrase(LatLng $point): string
    {
        try {
            $zone = collect($this->zones->zonesFor($point))->first();
            if ($zone !== null) {
                return 'in '.self::plainArea($zone->name);
            }
            $area = $this->zones->serviceAreaFor($point);
            if ($area !== null) {
                return 'in '.self::plainArea($area->name);
            }
        } catch (Throwable) {
            // Geo tables missing (an old install): say only what is certain.
        }

        return 'outside every service area';
    }

    /** "Greater Johannesburg (incl. OR Tambo)" reads as "Greater Johannesburg" inside a sentence. */
    private static function plainArea(string $name): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $name)) ?: $name;
    }

    /**
     * How far the point is from the nearest named stop, e.g. "350 m from the pickup (Oxford Road, Rosebank)".
     *
     * @param  array<string, array{lat: ?float, lng: ?float, label: ?string}>  $stops  role => stop
     */
    public function relativeTo(float $lat, float $lng, array $stops): ?string
    {
        $best = null;
        foreach ($stops as $role => $stop) {
            if (($stop['lat'] ?? null) === null || ($stop['lng'] ?? null) === null) {
                continue;
            }
            $metres = Haversine::metres(new LatLng($lat, $lng), new LatLng((float) $stop['lat'], (float) $stop['lng']));
            if ($best === null || $metres < $best[0]) {
                $best = [$metres, $role, $stop['label'] ?? null];
            }
        }

        if ($best === null) {
            return null;
        }

        [$metres, $role, $label] = $best;
        $distance = $metres < 50 ? 'At' : self::distance($metres).' from';
        $named = $label !== null && ! self::isPinned($label) ? ' ('.self::short($label).')' : '';

        return $distance.' the '.$role.$named;
    }

    public static function distance(float $metres): string
    {
        return $metres < 1000 ? (int) (round($metres / 10) * 10).' m' : number_format($metres / 1000, 1).' km';
    }

    /** Drops the postcode and the country from a geocoder label: "Oxford Road, Rosebank, Johannesburg". */
    public static function short(string $label): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $label)), fn ($p) => $p !== ''
            && ! preg_match('/^\d{4}$/', $p) && ! in_array(mb_strtolower($p), ['south africa', 'za', 'rsa'], true)));

        return implode(', ', array_slice($parts, 0, 3)) ?: trim($label);
    }
}
