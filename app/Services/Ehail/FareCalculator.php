<?php

namespace App\Services\Ehail;

use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\MoneyFormatter;

/**
 * Display helpers for the pre-R-46 views and notifications, now backed by the integer Money formatter.
 *
 * The old straight-line pricing (haversine distance x per-km x tier multiplier, in floats) was removed in R-46:
 * fares come only from App\Domain\Pricing (road routes, versioned rule sets, integer minor units; decision D-4).
 * The legacy request form prices through App\Domain\Pricing\Legacy\LegacyRideFareAdapter. Recorded fares of
 * existing rides are shown exactly as stored.
 */
class FareCalculator
{
    /**
     * "R 1,234.50" -- the configured currency symbol, display only. Accepts the legacy decimal columns.
     */
    public static function format(float|int|string $amount): string
    {
        $minor = Money::fromDecimal($amount, (string) config('ehail.currency.code', 'ZAR'))->amountMinor;

        return MoneyFormatter::formatMinor($minor, (string) config('ehail.currency.symbol'));
    }

    /**
     * Same as format() for an integer amount of cents (payments are stored in cents).
     */
    public static function formatCents(int $cents): string
    {
        return MoneyFormatter::formatMinor($cents, (string) config('ehail.currency.symbol'));
    }
}
