<?php

namespace App\Http\Controllers\Driver\Support;

use App\Support\Ui\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Times on the driver pages in the market's local time (the app stores and computes in UTC).
 */
final class Local
{
    public static function at(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // One display zone for the platform (D-1906): the driver setting only narrows it, and an unknown zone falls back.
        return Carbon::parse($value)->timezone(LocalTime::timezone((string) config('ehail_driver.timezone') ?: null));
    }
}
