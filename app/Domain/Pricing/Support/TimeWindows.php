<?php

namespace App\Domain\Pricing\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Weekly time windows in a named timezone: [{days: [1..7] (ISO, 1 = Monday), start: "HH:MM", end: "HH:MM"}].
 * A window whose end is not after its start runs past midnight; its `days` name the day it STARTS on, so
 * {days: [5], start: "22:00", end: "04:00"} is Friday 22:00 to Saturday 04:00. start == end means the whole day.
 * A null or empty list means "always". Start is inclusive, end exclusive.
 */
final class TimeWindows
{
    /**
     * @param  list<array{days?: list<int>, start?: string, end?: string}>|null  $windows
     */
    public static function contains(?array $windows, CarbonInterface $at, string $timezone): bool
    {
        if ($windows === null || $windows === []) {
            return true;
        }

        $local = $at->copy()->setTimezone($timezone);
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $day = (int) $local->format('N');
        $previousDay = $day === 1 ? 7 : $day - 1;

        foreach ($windows as $window) {
            $days = array_map('intval', $window['days'] ?? [1, 2, 3, 4, 5, 6, 7]);
            $start = self::minutes($window['start'] ?? '00:00');
            $end = self::minutes($window['end'] ?? '24:00');

            if ($start === $end) {
                if (in_array($day, $days, true)) {
                    return true;
                }

                continue;
            }

            if ($start < $end) {
                if (in_array($day, $days, true) && $minute >= $start && $minute < $end) {
                    return true;
                }

                continue;
            }

            // Crosses midnight: the evening part belongs to today, the early-morning part to yesterday's window.
            if (in_array($day, $days, true) && $minute >= $start) {
                return true;
            }

            if (in_array($previousDay, $days, true) && $minute < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $windows
     * @return list<string> validation errors (empty = valid)
     */
    public static function validate(mixed $windows): array
    {
        if ($windows === null) {
            return [];
        }

        if (! is_array($windows) || ! array_is_list($windows)) {
            return ['time_windows must be a list.'];
        }

        $errors = [];

        foreach ($windows as $i => $window) {
            if (! is_array($window)) {
                $errors[] = "time_windows.{$i} must be an object.";

                continue;
            }

            foreach ($window['days'] ?? [] as $d) {
                if (! is_int($d) || $d < 1 || $d > 7) {
                    $errors[] = "time_windows.{$i}.days must hold ISO weekdays 1-7.";
                }
            }

            foreach (['start', 'end'] as $edge) {
                try {
                    self::minutes($window[$edge] ?? ($edge === 'start' ? '00:00' : '24:00'));
                } catch (InvalidArgumentException $e) {
                    $errors[] = "time_windows.{$i}.{$edge}: ".$e->getMessage();
                }
            }
        }

        return $errors;
    }

    public static function minutes(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $m) !== 1 || ((int) $m[1] === 24 && (int) $m[2] !== 0)) {
            throw new InvalidArgumentException("'{$time}' is not a time of day (HH:MM, 00:00 to 24:00).");
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
