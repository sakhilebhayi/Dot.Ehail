<?php

namespace App\Domain\Geo\Geometry;

use App\Domain\Geo\LatLng;
use InvalidArgumentException;

/**
 * Google's encoded polyline algorithm. Precision 5 is the house format (Google, Mapbox "polyline", OSRM, ORS);
 * Valhalla returns precision 6 and is re-encoded at the adapter.
 */
final class Polyline
{
    /**
     * @param  list<LatLng>  $points
     */
    public static function encode(array $points, int $precision = 5): string
    {
        $factor = 10 ** $precision;
        $output = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as $point) {
            $lat = (int) round($point->lat * $factor);
            $lng = (int) round($point->lng * $factor);
            $output .= self::encodeValue($lat - $prevLat).self::encodeValue($lng - $prevLng);
            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $output;
    }

    /**
     * @return list<LatLng>
     */
    public static function decode(string $encoded, int $precision = 5): array
    {
        $factor = 10 ** $precision;
        $length = strlen($encoded);
        $index = 0;
        $lat = 0;
        $lng = 0;
        $points = [];

        while ($index < $length) {
            $lat += self::decodeValue($encoded, $index, $length);
            $lng += self::decodeValue($encoded, $index, $length);
            $points[] = new LatLng($lat / $factor, $lng / $factor);
        }

        return $points;
    }

    private static function encodeValue(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunk = '';

        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.chr($value + 63);
    }

    private static function decodeValue(string $encoded, int &$index, int $length): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($index >= $length) {
                throw new InvalidArgumentException('Truncated polyline.');
            }

            $byte = ord($encoded[$index++]) - 63;

            if ($byte < 0 || $byte > 63) {
                throw new InvalidArgumentException('Invalid polyline character.');
            }

            $result |= ($byte & 0x1F) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }
}
