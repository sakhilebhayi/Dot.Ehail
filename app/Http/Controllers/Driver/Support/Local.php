<?php

namespace App\Http\Controllers\Driver\Support;

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

        return Carbon::parse($value)->timezone((string) config('ehail_driver.timezone', 'Africa/Johannesburg'));
    }
}
