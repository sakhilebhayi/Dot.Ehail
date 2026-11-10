<?php

namespace App\Support\Ui;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The one display helper for times people read (D-1906). Everything is stored in UTC; every user-facing time
 * (receipts, trips, ride pages, safety "what we did", the trip-share page, ops timelines, statements, emails,
 * notifications, CSV exports) is shown in the service area's timezone: South African Standard Time
 * (Africa/Johannesburg, UTC+2, no daylight saving) unless a service area says otherwise. The browser side is
 * resources/js/eh/time.js with the same default.
 */
final class LocalTime
{
    public const DEFAULT_TIMEZONE = 'Africa/Johannesburg';

    /** The display timezone: a given service area's zone when known, else the platform's (config). */
    public static function timezone(?string $serviceAreaTimezone = null): string
    {
        $tz = $serviceAreaTimezone ?: (string) config('ehail_pricing.default_timezone', self::DEFAULT_TIMEZONE);

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : self::DEFAULT_TIMEZONE;
    }

    public static function at(CarbonInterface|DateTimeInterface|string|null $value, ?string $tz = null): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $instant->setTimezone(self::timezone($tz));
    }

    /** "10 Nov 2026, 09:33" in local time; null for nothing. */
    public static function format(CarbonInterface|DateTimeInterface|string|null $value, string $pattern = 'j M Y, H:i', ?string $tz = null): ?string
    {
        return self::at($value, $tz)?->format($pattern);
    }

    /** "09:33" */
    public static function time(CarbonInterface|DateTimeInterface|string|null $value, bool $seconds = false, ?string $tz = null): ?string
    {
        return self::format($value, $seconds ? 'H:i:s' : 'H:i', $tz);
    }

    /** The zone's short name for labels and CSV headers: "SAST" for Johannesburg. */
    public static function label(?string $tz = null): string
    {
        $zone = self::timezone($tz);
        $abbr = CarbonImmutable::now($zone)->format('T');

        return $zone === self::DEFAULT_TIMEZONE ? 'SAST' : (preg_match('/^[A-Z]{2,5}$/', $abbr) ? $abbr : $zone);
    }
}
