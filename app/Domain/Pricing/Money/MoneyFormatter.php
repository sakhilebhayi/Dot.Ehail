<?php

namespace App\Domain\Pricing\Money;

/**
 * "R 1,234.50": symbol, a space, grouped digits, the currency's minor digits. Display only; built from integers.
 */
final class MoneyFormatter
{
    public static function format(Money $money): string
    {
        $currency = Currency::isSupported($money->currency) ? Currency::of($money->currency) : new Currency($money->currency, $money->currency, 2);

        return self::formatMinor($money->amountMinor, $currency->symbol, $currency->exponent);
    }

    public static function formatMinor(int $amountMinor, string $symbol, int $exponent = 2): string
    {
        $sign = $amountMinor < 0 ? '-' : '';
        $decimal = self::decimal(abs($amountMinor), $exponent);
        [$whole, $fraction] = array_pad(explode('.', $decimal), 2, '');
        $grouped = ltrim(strrev(implode(',', str_split(strrev($whole), 3))), ',');

        return $sign.$symbol.' '.$grouped.($exponent > 0 ? '.'.$fraction : '');
    }

    /**
     * Plain decimal string with the currency's minor digits: 8000 -> "80.00", -5 -> "-0.05".
     */
    public static function decimal(int $amountMinor, int $exponent = 2): string
    {
        if ($exponent === 0) {
            return (string) $amountMinor;
        }

        $sign = $amountMinor < 0 ? '-' : '';
        $digits = str_pad((string) abs($amountMinor), $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}
