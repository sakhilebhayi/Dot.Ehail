<?php

namespace App\Domain\Ops\Support;

/** Duration words for operations screens; the same wording as resources/js/ops/helpers.js formatDuration. */
final class Durations
{
    public static function short(?int $seconds): string
    {
        if ($seconds === null) {
            return 'not recorded';
        }

        $s = abs($seconds);
        if ($s < 60) {
            return 'under 1 min';
        }

        $minutes = intdiv($s, 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 48) {
            return $hours.' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' min';
        }

        return intdiv($hours, 24).' d '.($hours % 24).' h';
    }
}
