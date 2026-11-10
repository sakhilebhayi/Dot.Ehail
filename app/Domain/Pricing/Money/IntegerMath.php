<?php

namespace App\Domain\Pricing\Money;

use InvalidArgumentException;
use OverflowException;

/**
 * Integer-only arithmetic for money. PHP silently turns an overflowing int into a float; every operation here
 * refuses that instead, so a float can never reach a fare.
 */
final class IntegerMath
{
    public static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new OverflowException('Integer overflow in money addition.');
        }

        return $a + $b;
    }

    public static function subtract(int $a, int $b): int
    {
        if (($b < 0 && $a > PHP_INT_MAX + $b) || ($b > 0 && $a < PHP_INT_MIN + $b)) {
            throw new OverflowException('Integer overflow in money subtraction.');
        }

        return $a - $b;
    }

    public static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN || abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new OverflowException('Integer overflow in money multiplication.');
        }

        return $a * $b;
    }

    /**
     * numerator / denominator, rounded by $mode. The denominator must be positive.
     */
    public static function divide(int $numerator, int $denominator, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator must be a positive integer.');
        }

        if ($numerator === PHP_INT_MIN) {
            throw new OverflowException('Integer overflow in money division.');
        }

        $negative = $numerator < 0;
        $absolute = $negative ? -$numerator : $numerator;

        $quotient = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;

        if ($remainder !== 0) {
            $twiceRemainder = self::multiply($remainder, 2);

            $roundAway = match ($mode) {
                RoundingMode::Up => true,
                RoundingMode::Down => false,
                RoundingMode::HalfUp => $twiceRemainder >= $denominator,
                RoundingMode::HalfEven => $twiceRemainder > $denominator || ($twiceRemainder === $denominator && $quotient % 2 === 1),
            };

            if ($roundAway) {
                $quotient++;
            }
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * $value rounded to a multiple of $unit (e.g. cents to whole rand with $unit = 100).
     */
    public static function roundToMultiple(int $value, int $unit, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        if ($unit <= 1) {
            return $value;
        }

        return self::multiply(self::divide($value, $unit, $mode), $unit);
    }
}
