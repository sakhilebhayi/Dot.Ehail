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
        $result = $a + $b;

        if (! is_int($result)) {
            throw new OverflowException('Integer overflow in money addition.');
        }

        return $result;
    }

    public static function subtract(int $a, int $b): int
    {
        $result = $a - $b;

        if (! is_int($result)) {
            throw new OverflowException('Integer overflow in money subtraction.');
        }

        return $result;
    }

    public static function multiply(int $a, int $b): int
    {
        $result = $a * $b;

        if (! is_int($result)) {
            throw new OverflowException('Integer overflow in money multiplication.');
        }

        return $result;
    }

    /**
     * numerator / denominator, rounded by $mode. The denominator must be positive.
     */
    public static function divide(int $numerator, int $denominator, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator must be a positive integer.');
        }

        $negative = $numerator < 0;
        $absolute = $negative ? -$numerator : $numerator;

        if (! is_int($absolute)) {
            throw new OverflowException('Integer overflow in money division.');
        }

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
