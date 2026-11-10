<?php

namespace App\Domain\Pricing\Money;

/**
 * How an integer division that does not come out even is settled.
 * HalfUp rounds a half away from zero (R 2.50 -> R 3, -R 2.50 -> -R 3), the usual retail rule.
 */
enum RoundingMode: string
{
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
    case Up = 'up';
    case Down = 'down';
}
