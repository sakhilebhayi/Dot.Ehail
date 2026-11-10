<?php

namespace App\Domain\Ops\Insights;

use Carbon\CarbonImmutable;

/**
 * Period buckets for insights: day, ISO week (Monday start) or calendar month, each listing the 'YYYY-MM-DD'
 * days it covers inside [from, to]. Days are UTC dates as stored (the operations market, South Africa, is UTC+2
 * with no daylight saving; a trip at 01:30 SAST counts on the previous UTC day, stated on the insights page).
 */
final class Periods
{
    /** @return list<array{key: string, label: string, days: list<string>}> */
    public static function buckets(CarbonImmutable $from, CarbonImmutable $to, string $grain): array
    {
        $buckets = [];
        $day = $from->startOfDay();
        $last = $to->startOfDay();

        while ($day->lessThanOrEqualTo($last)) {
            [$key, $label] = match ($grain) {
                'month' => [$day->format('Y-m'), $day->format('M Y')],
                'week' => [$day->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'), 'Week of '.$day->startOfWeek(CarbonImmutable::MONDAY)->format('d M')],
                default => [$day->format('Y-m-d'), $day->format('d M')],
            };
            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'days' => []];
            $buckets[$key]['days'][] = $day->format('Y-m-d');
            $day = $day->addDay();
        }

        return array_values($buckets);
    }
}
