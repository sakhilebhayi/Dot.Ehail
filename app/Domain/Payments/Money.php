<?php

namespace App\Domain\Payments;

/**
 * Integer minor-unit helpers. No float arithmetic on amounts: decimals are converted by string,
 * percentages by basis points with half-up rounding.
 */
final class Money
{
    public static function currency(): string
    {
        return strtoupper((string) config('money.currency', 'ZAR'));
    }

    /** "125.5" / 125.50 -> 12550, half up at the third decimal. */
    public static function decimalToMinor(mixed $amount): int
    {
        $text = is_string($amount) ? trim($amount) : number_format((float) $amount, 3, '.', '');
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-+');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = str_pad(preg_replace('/\D/', '', $fraction), 3, '0');
        $minor = ((int) preg_replace('/\D/', '', $whole ?: '0')) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    /** Percent (e.g. 17.5) of an amount, rounded half up to the minor unit. */
    public static function percentOf(int $amountMinor, float $percent): int
    {
        $basisPoints = (int) round($percent * 100);
        $product = $amountMinor * $basisPoints;

        return intdiv($product + ($product >= 0 ? 5000 : -5000), 10000);
    }

    public static function format(int $minor, ?string $currency = null): string
    {
        // The platform's one money text (D-1903).
        return \App\Support\Ui\MoneyFormatter::format($minor, $currency ?? self::currency());
    }

    /** Minor units as a plain decimal string for CSV ("125.50"). */
    public static function toDecimalString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
