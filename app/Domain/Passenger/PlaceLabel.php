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

    /** A part that is a street: an English or Afrikaans street word at the end (Afrikaans joins it on: Birdstraat), or a road number. */
    private const STREET = '/\\b(road|rd|street|st|straat|avenue|ave|laan|drive|dr|rylaan|lane|ln|way|weg|crescent|cres|close|place|boulevard|blvd|highway|hwy|freeway|square|terrace|walk|route|[NMR]\\d{1,3})\\.?$|(straat|laan|weg|singel)$/iu';

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

    /**
     * The area a place is in, with no street, building or number ("Sandhurst" for Sandton City; "Soweto" for
     * "12 Secret Home Road, Soweto"); '' when the label names no area. For pages other people read, such as the
     * public trip-share page, where an address must not appear (D-2011).
     */
    public static function area(?string $label): string
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $label)),
            fn (string $part) => $part !== '' && ! preg_match('/^\d{4}$/', $part) && ! in_array(mb_strtolower($part), self::COUNTRIES, true),
        ));

        // The first part is the place itself; an area is a later part that is not a number or a street.
        foreach (array_slice($parts, 1) as $part) {
            if (! preg_match('/\d/', $part) && ! preg_match(self::STREET, $part)) {
                return $part;
            }
        }

        return '';
    }
}
