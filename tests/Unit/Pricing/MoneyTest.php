<?php

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\Money\CurrencyMismatch;
use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\MoneyFormatter;
use App\Domain\Pricing\Money\RoundingMode;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_stays_in_integer_minor_units(): void
    {
        $a = Money::of(1050, 'ZAR');
        $b = Money::of(250, 'ZAR');

        $this->assertSame(1300, $a->plus($b)->amountMinor);
        $this->assertSame(800, $a->minus($b)->amountMinor);
        $this->assertSame(-1050, $a->negate()->amountMinor);
        $this->assertSame(3150, $a->times(3)->amountMinor);
        $this->assertIsInt($a->applyBasisPoints(12_345)->amountMinor);
    }

    public function test_mixing_currencies_is_refused(): void
    {
        $this->expectException(CurrencyMismatch::class);
        Money::of(100, 'ZAR')->plus(Money::of(100, 'USD'));
    }

    public function test_invalid_currency_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'zar');
    }

    public function test_overflow_is_refused_instead_of_becoming_a_float(): void
    {
        $this->expectException(OverflowException::class);
        Money::of(PHP_INT_MAX, 'ZAR')->plus(Money::of(1, 'ZAR'));
    }

    public function test_multiplication_overflow_is_refused(): void
    {
        $this->expectException(OverflowException::class);
        Money::of(PHP_INT_MAX, 'ZAR')->applyBasisPoints(20_000);
    }

    /**
     * @return array<string, array{int, int, RoundingMode, int}>
     */
    public static function divisions(): array
    {
        return [
            'half up rounds half away from zero' => [5, 2, RoundingMode::HalfUp, 3],
            'half up negative' => [-5, 2, RoundingMode::HalfUp, -3],
            'half up below half' => [4, 3, RoundingMode::HalfUp, 1],
            'half even to even down' => [5, 2, RoundingMode::HalfEven, 2],
            'half even to even up' => [7, 2, RoundingMode::HalfEven, 4],
            'half even above half' => [8, 3, RoundingMode::HalfEven, 3],
            'up' => [11, 10, RoundingMode::Up, 2],
            'down' => [19, 10, RoundingMode::Down, 1],
            'down negative toward zero' => [-19, 10, RoundingMode::Down, -1],
            'exact' => [20, 10, RoundingMode::Up, 2],
        ];
    }

    #[DataProvider('divisions')]
    public function test_integer_division_rounding_modes(int $numerator, int $denominator, RoundingMode $mode, int $expected): void
    {
        $this->assertSame($expected, IntegerMath::divide($numerator, $denominator, $mode));
    }

    public function test_round_to_whole_rand(): void
    {
        $this->assertSame(8100, Money::of(8050, 'ZAR')->roundToUnit(100)->amountMinor);
        $this->assertSame(8000, Money::of(8049, 'ZAR')->roundToUnit(100)->amountMinor);
        $this->assertSame(8000, Money::of(8050, 'ZAR')->roundToUnit(100, RoundingMode::HalfEven)->amountMinor);
        $this->assertSame(8049, Money::of(8049, 'ZAR')->roundToUnit(1)->amountMinor);
    }

    public function test_formatting(): void
    {
        $this->assertSame('R 1,234.50', Money::of(123450, 'ZAR')->format());
        $this->assertSame('R 0.05', Money::of(5, 'ZAR')->format());
        $this->assertSame('-R 12.00', Money::of(-1200, 'ZAR')->format());
        $this->assertSame('R 1,000,000.00', Money::of(100_000_000, 'ZAR')->format());
        $this->assertSame('80.00', Money::of(8000, 'ZAR')->toDecimalString());
        $this->assertSame('-0.05', MoneyFormatter::decimal(-5));
        $this->assertSame(['amount_minor' => 8000, 'currency' => 'ZAR', 'formatted' => 'R 80.00'], Money::of(8000, 'ZAR')->jsonSerialize());
    }

    public function test_parsing_legacy_decimals_exactly(): void
    {
        $this->assertSame(8000, Money::fromDecimal('80.00', 'ZAR')->amountMinor);
        $this->assertSame(123450, Money::fromDecimal('1234.5', 'ZAR')->amountMinor);
        $this->assertSame(1, Money::fromDecimal('0.005', 'ZAR')->amountMinor);
        $this->assertSame(-250, Money::fromDecimal('-2.50', 'ZAR')->amountMinor);
        $this->assertSame(7000, Money::fromDecimal(70, 'ZAR')->amountMinor);
        $this->assertSame(5550, Money::fromDecimal(55.5, 'ZAR')->amountMinor);
    }

    public function test_property_addition_is_associative_and_commutative_and_basis_points_are_bounded(): void
    {
        mt_srand(4242);

        for ($i = 0; $i < 500; $i++) {
            $a = Money::of(mt_rand(-1_000_000, 1_000_000), 'ZAR');
            $b = Money::of(mt_rand(-1_000_000, 1_000_000), 'ZAR');
            $c = Money::of(mt_rand(-1_000_000, 1_000_000), 'ZAR');

            $this->assertTrue($a->plus($b)->equals($b->plus($a)));
            $this->assertTrue($a->plus($b)->plus($c)->equals($a->plus($b->plus($c))));
            $this->assertTrue($a->minus($a)->isZero());

            $bp = mt_rand(0, 30_000);
            $scaled = $a->applyBasisPoints($bp);
            // Rounding error is at most half a minor unit from the exact rational result.
            $this->assertLessThanOrEqual(5_000, abs($scaled->amountMinor * 10_000 - $a->amountMinor * $bp));

            $unit = [1, 10, 100, 500][mt_rand(0, 3)];
            $rounded = $a->roundToUnit($unit);
            $this->assertSame(0, $rounded->amountMinor % $unit);
            $this->assertLessThanOrEqual(intdiv($unit, 2), abs($rounded->amountMinor - $a->amountMinor));
        }
    }
}
