<?php

namespace App\Domain\Payments\Seams;

/**
 * How a final fare divides between the platform and the driver (the contract's Split).
 * gross = tax + platform fees + commission + driver deductions + driver net (all minor units).
 */
final readonly class EarningsSplit
{
    /**
     * @param  array<int, array{code: string, label: string, amount_minor: int}>  $deductions
     */
    public function __construct(
        public int $commissionMinor,
        public int $driverGrossMinor,
        public array $deductions,
        public int $driverNetMinor,
        public string $plan,
    ) {}

    public function deductionsMinor(): int
    {
        return array_sum(array_column($this->deductions, 'amount_minor'));
    }
}
