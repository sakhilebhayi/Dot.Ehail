<?php

namespace App\Domain\Business;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business time: spend months, policy days and hours and statement periods are counted in the business
 * timezone (config ehail_business.timezone); everything is stored in UTC.
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('ehail_business.timezone', 'Africa/Johannesburg');
    }

    public static function local(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone(self::timezone());
    }

    /** "2026-11" for the month the moment falls in, locally. */
    public static function monthKey(?CarbonInterface $at = null): string
    {
        return self::local($at)->format('Y-m');
    }

    /**
     * UTC bounds [start, end) of a local month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthBounds(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, self::timezone());

        return [$start->utc(), $start->addMonth()->utc()];
    }

    public static function previousMonthKey(?CarbonInterface $at = null): string
    {
        return self::local($at)->startOfMonth()->subMonth()->format('Y-m');
    }

    public static function monthLabel(string $period): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period, self::timezone())->format('F Y');
    }
}
