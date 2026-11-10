<?php

namespace App\Support\Ui;

/**
 * Display formatting for integer minor-unit amounts (docs/R46_ARCHITECTURE.md section 0: money is never a float).
 * South African style by default: "R1 250.50", a narrow no-break space between thousands, a point before cents.
 * Formatting only: arithmetic stays in the money domain.
 */
final class MoneyFormatter
{
    /** ISO 4217 minor-unit exponents that are not 2. */
    private const EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'UGX' => 0, 'RWF' => 0, 'XOF' => 0, 'XAF' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3];

    private const SYMBOLS = ['ZAR' => 'R', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NAD' => 'N$', 'BWP' => 'P', 'KES' => 'KSh', 'NGN' => '₦', 'GHS' => 'GH₵', 'LSL' => 'L', 'SZL' => 'E'];

    private const THOUSANDS = "\u{202F}";

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public static function symbol(string $currency): string
    {
        $currency = strtoupper($currency);

        return self::SYMBOLS[$currency] ?? $currency.' ';
    }

    /**
     * @param  bool  $signed  show a leading "+" for positive amounts (credits, adjustments)
     * @param  bool  $wholeUnits  drop the minor part (only when it is zero, so nothing is hidden)
     */
    public static function format(int $minor, string $currency = 'ZAR', bool $signed = false, bool $wholeUnits = false): string
    {
        return self::withSymbol($minor, self::symbol($currency), self::exponent($currency), $signed, $wholeUnits);
    }

    /**
     * The one money text of the platform (D-1903): every formatter (pricing Money, payments Money, the legacy
     * FareCalculator, Blade, JS money.js) produces exactly this shape.
     */
    public static function withSymbol(int $minor, string $symbol, int $exponent = 2, bool $signed = false, bool $wholeUnits = false): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $divisor = 10 ** $exponent;
        $major = intdiv($absolute, $divisor);
        $fraction = $absolute % $divisor;

        $number = number_format($major, 0, '.', self::THOUSANDS);
        if ($exponent > 0 && ! ($wholeUnits && $fraction === 0)) {
            $number .= '.'.str_pad((string) $fraction, $exponent, '0', STR_PAD_LEFT);
        }

        $sign = $negative ? "\u{2212}" : ($signed && $minor > 0 ? '+' : '');

        // A bare ISO code ("USD") is separated from the number; a symbol ("R", "N$") is not.
        $symbol = trim($symbol);

        return $sign.$symbol.(preg_match('/^[A-Z]{3}$/', $symbol) ? ' ' : '').$number;
    }

    /** Words for screen readers: "R125.50" is read well; a minus sign glyph is not, so negative amounts say "minus". */
    public static function spoken(int $minor, string $currency = 'ZAR'): string
    {
        $text = str_replace(self::THOUSANDS, '', self::format(abs($minor), $currency));

        return ($minor < 0 ? 'minus ' : '').$text;
    }
}
