<?php

namespace App\Support\Ui;

/** Human journey timing: "1 min", "12 min", "1 h 05 min". Never a decimal hour. */
final class Duration
{
    public static function short(int $seconds): string
    {
        $minutes = max(1, (int) ceil(max(0, $seconds) / 60));
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' min';
    }

    public static function spoken(int $seconds): string
    {
        $minutes = max(1, (int) ceil(max(0, $seconds) / 60));
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }
        if ($rest > 0 || $hours === 0) {
            $parts[] = $rest.' '.($rest === 1 ? 'minute' : 'minutes');
        }

        return implode(' ', $parts);
    }
}
