<?php

namespace App\Domain\Pricing\Earnings;

use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Money\Money;

/**
 * How one fare divides between driver and platform. When no commission plan is configured, commission and net are
 * null ("not configured") rather than a guessed zero.
 *
 * driver_gross = commissionable fare + tolls passed through at cost
 * driver_net   = driver_gross - deductions (commission)
 * platform     = passenger total - driver_net (includes booking/zone fees and tax, minus platform-funded promotions)
 */
final readonly class Split
{
    /**
     * @param  list<array{code: string, label: string, amount_minor: int, detail: ?string}>  $deductions
     */
    public function __construct(
        public string $currency,
        public int $fareMinor,
        public int $commissionableMinor,
        public int $passThroughMinor,
        public ?int $commissionMinor,
        public int $driverGrossMinor,
        public array $deductions,
        public ?int $driverNetMinor,
        public ?int $platformMinor,
        public ?CommissionPlan $plan,
        public bool $isEstimate,
    ) {}

    /**
     * What the driver sees (before accepting: estimate; after completion: actual).
     *
     * @return array<string, mixed>
     */
    public function toDriverArray(): array
    {
        $m = fn (?int $minor) => $minor === null ? null : Money::of($minor, $this->currency);

        return [
            'kind' => $this->isEstimate ? 'estimate' : 'actual',
            'fare' => $m($this->fareMinor),
            'driver_gross' => $m($this->driverGrossMinor),
            'commission' => $m($this->commissionMinor),
            'deductions' => array_map(fn (array $d) => $d + ['amount' => $m($d['amount_minor'])], $this->deductions),
            'tolls_reimbursed' => $m($this->passThroughMinor),
            'driver_net' => $m($this->driverNetMinor),
            'commission_plan' => $this->plan === null ? null : ['id' => $this->plan->id, 'version' => $this->plan->version, 'name' => $this->plan->name, 'is_example' => $this->plan->is_example],
            'note' => $this->plan === null ? 'No commission plan is configured for this market yet, so your net earnings cannot be shown.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'fare_minor' => $this->fareMinor,
            'commissionable_minor' => $this->commissionableMinor,
            'pass_through_minor' => $this->passThroughMinor,
            'commission_minor' => $this->commissionMinor,
            'driver_gross_minor' => $this->driverGrossMinor,
            'deductions' => $this->deductions,
            'driver_net_minor' => $this->driverNetMinor,
            'platform_minor' => $this->platformMinor,
            'commission_plan_id' => $this->plan?->id,
            'commission_plan_version' => $this->plan?->version,
            'is_estimate' => $this->isEstimate,
        ];
    }
}
