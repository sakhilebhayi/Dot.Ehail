<?php

namespace App\Domain\Integration\Money;

use App\Domain\Payments\Seams\ConfigCommissionSplitter;
use App\Domain\Payments\Seams\EarningsSplit;
use App\Domain\Payments\Seams\EarningsSplitSource;
use App\Domain\Payments\Seams\FareBreakdown;
use App\Domain\Pricing\Earnings\DriverArrangement;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown as PricingBreakdown;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\User;

/**
 * The driver's share from pricing's commission plans (D-708): pricing's split of the finalised fare for this
 * driver's arrangement (fleet, area, category). Money keeps commission separate from deductions, so pricing's
 * "commission" deduction becomes commissionMinor and no other deductions exist. Without a pricing fare record
 * or without a published commission plan, money's configured platform rate applies (money D-617), labelled as
 * such on the earning.
 */
class PricingEarningsSplitSource implements EarningsSplitSource
{
    public function __construct(private readonly EarningsSplitter $splitter, private readonly ConfigCommissionSplitter $fallback) {}

    public function split(Ride $ride, FareBreakdown $fare, User $driver): EarningsSplit
    {
        $record = RideFare::query()->where('ride_id', $ride->id)->first();
        $arrangement = $record?->isFinalised() ? DriverArrangement::forRide($ride, $driver->id) : null;

        if ($record === null || $arrangement === null || $fare->source !== 'ride_fares') {
            return $this->fallback->split($ride, $fare, $driver);
        }

        $split = $this->splitter->splitBreakdown(
            PricingBreakdown::fromComponentsArray($record->final_components ?? [], $record->currency, $record->demand_multiplier_bp),
            $arrangement,
            $record->finalised_at ?? now(),
            false,
        );

        if ($split->commissionMinor === null || $split->driverNetMinor === null || $split->plan === null) {
            return $this->fallback->split($ride, $fare, $driver);
        }

        return new EarningsSplit(
            commissionMinor: $split->commissionMinor,
            driverGrossMinor: $split->driverGrossMinor,
            deductions: [],
            driverNetMinor: $split->driverNetMinor,
            plan: sprintf('%s (commission plan #%d v%d)', $split->plan->name, $split->plan->id, $split->plan->version),
        );
    }
}
