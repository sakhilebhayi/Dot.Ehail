<?php

namespace App\Domain\Passenger;

/**
 * Place names for people, from the geocoder's long labels ("Sandton City, Rivonia Road, Sandhurst, Sandton, 2196,
 * South Africa"): the name, the area it is in, and a short form. Postcodes and the country are dropped (the
 * service runs in one country), so a label reads like a person would say it (R-46 ppass, D-2004).
 * resources/js/passenger/places.js applies the same rules in the browser.
 */
final class PlaceLabel
{
    /** @var list<string> */
    private const COUNTRIES = ['south africa', 'suid-afrika', 'mzansi'];

    /**
     * @return array{name: string, detail: string, short: string}
     */
    public static function split(?string $label): array
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $label)),
            fn (string $part) => $part !== '' && ! preg_match('/^\d{4}$/', $part) && ! in_array(mb_strtolower($part), self::COUNTRIES, true),
        ));

        if ($parts === []) {
            return ['name' => trim((string) $label), 'detail' => '', 'short' => trim((string) $label)];
        }

        // A house number on its own ("12, Bree Street") belongs with the street.
        if (count($parts) > 1 && preg_match('/^\d+[A-Za-z]?$/', $parts[0])) {
            $parts = [$parts[0].' '.$parts[1], ...array_slice($parts, 2)];
        }

        $name = $parts[0];
        $rest = array_slice($parts, 1);
        // Two area names are enough to place it ("Rivonia Road, Sandhurst"); the metro after that adds little.
        $detail = implode(', ', array_slice($rest, 0, 2));

        return [
            'name' => $name,
            'detail' => $detail,
            'short' => $rest === [] ? $name : $name.', '.$rest[0],
        ];
    }

    public static function short(?string $label): string
    {
        return self::split($label)['short'];
    }
}
