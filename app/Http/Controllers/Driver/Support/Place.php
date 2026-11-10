<?php

namespace App\Http\Controllers\Driver\Support;

/**
 * Addresses as a driver reads them at a glance (D-2102): the street (or the place and its street) and the suburb,
 * without the city, postcode, province or country. The same rule as shortAddress() in resources/js/driver/geometry.js.
 * The full address stays on the trip page and one tap away on the console.
 */
final class Place
{
    private const PROVINCES = '/^(gauteng|western cape|eastern cape|northern cape|kwazulu-natal|kwazulu natal|free state|limpopo|mpumalanga|north west)$/i';

    private const STREETISH = '/\d|\b(road|rd|street|str|st|avenue|ave|drive|dr|lane|ln|boulevard|blvd|way|crescent|cres|close|highway|hwy|place|pl|square|sq|terrace|circle|loop|parkway|freeway|mall)\b/i';

    public static function short(?string $address): string
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $address)),
            fn (string $p) => $p !== '' && ! preg_match('/^\d{4}$/', $p) && ! preg_match('/^south africa$/i', $p) && ! preg_match(self::PROVINCES, $p),
        ));

        if (count($parts) <= 2) {
            return implode(', ', $parts);
        }

        $suburb = $parts[count($parts) - 2];
        $out = [$parts[0]];

        if (! preg_match(self::STREETISH, $parts[0]) && count($parts) >= 4 && preg_match(self::STREETISH, $parts[1])) {
            $out[] = $parts[1];
        }

        if (! in_array($suburb, $out, true)) {
            $out[] = $suburb;
        }

        return implode(', ', $out);
    }
}
