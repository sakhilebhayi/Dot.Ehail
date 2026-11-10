<?php

namespace App\Domain\Pricing\Earnings;

use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Finalisation\FinalFare;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\Currency;
use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\MoneyFormatter;
use App\Domain\Pricing\Money\RoundingMode;
use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * Fare -> commission -> driver earnings (architecture section 3). Commission is percent_bp of the commissionable
 * components plus fixed_minor, clamped to [min, max] and to the commissionable amount itself. Tolls are reimbursed to
 * the driver at cost and never commissioned. The same function gives the estimate a driver sees on an offer (from
 * the quote) and the actual after completion (from the final fare), so the two are directly comparable.
 */
final class EarningsSplitter
{
    public function __construct(private CommissionPlanResolver $plans) {}

    public function split(FinalFare $fare, DriverArrangement $arrangement, ?CarbonInterface $at = null): Split
    {
        return $this->splitBreakdown($fare->breakdown, $arrangement, $at ?? $fare->rideFare->finalised_at ?? now(), false);
    }

    public function estimateForQuote(FareQuote $quote, DriverArrangement $arrangement, ?CarbonInterface $at = null): Split
    {
        return $this->splitBreakdown(FareBreakdown::fromComponentsArray($quote->components, $quote->currency, $quote->demand_multiplier_bp), $arrangement, $at ?? now(), true);
    }

    /**
     * The estimate for a ride already bound to a quote (driver offer card): uses the ride's estimated snapshot.
     */
    public function estimateForRide(Ride $ride, ?int $driverUserId = null): ?Split
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();
        $arrangement = DriverArrangement::forRide($ride, $driverUserId);

        if ($fare === null || $arrangement === null) {
            return null;
        }

        return $this->splitBreakdown(FareBreakdown::fromComponentsArray($fare->estimated_components, $fare->currency, $fare->demand_multiplier_bp), $arrangement, now(), true);
    }

    public function splitBreakdown(FareBreakdown $breakdown, DriverArrangement $arrangement, CarbonInterface $at, bool $isEstimate): Split
    {
        $plan = $this->plans->resolve($arrangement, $at);
        $codes = $plan?->commissionableCodes() ?? CommissionPlan::DEFAULT_COMMISSIONABLE;

        $commissionable = 0;

        foreach ($codes as $code) {
            $commissionable = IntegerMath::add($commissionable, $breakdown->amountFor($code));
        }

        $rawCommissionable = $commissionable;
        $commissionable = max(0, $commissionable);
        $tolls = max(0, $breakdown->amountFor('tolls'));
        $gross = IntegerMath::add($commissionable, $tolls);
        $fare = $breakdown->totalMinor();
        $currency = $breakdown->currency;
        $money = fn (int $minor) => MoneyFormatter::formatMinor($minor, Currency::of($currency)->symbol, Currency::of($currency)->exponent);

        // The walk from the passenger's fare to what the driver keeps (D-1901): every line the driver does not earn
        // on is listed, so fare + lines = net exactly and the commission names the amount it is a percentage of.
        $lines = [];
        $excludedFees = false;

        foreach ($breakdown->components as $component) {
            if ($component->code === 'tolls' || str_starts_with($component->code, 'tolls:') || $component->amountMinor === 0 || self::matches($component->code, $codes)) {
                continue;
            }

            if ($component->amountMinor < 0) {
                $lines[] = ['code' => 'platform_funded:'.$component->code, 'label' => $component->label.', paid by the platform', 'amount_minor' => -$component->amountMinor, 'detail' => 'You earn on the fare before this discount'];

                continue;
            }

            $excludedFees = true;
            $lines[] = ['code' => 'not_earned:'.$component->code, 'label' => $component->label.', kept by the platform', 'amount_minor' => -$component->amountMinor, 'detail' => 'Not part of your earnings and not commissioned'];
        }

        if ($rawCommissionable < 0) {
            $lines[] = ['code' => 'commissionable_floor', 'label' => 'Trip fare cannot go below zero', 'amount_minor' => -$rawCommissionable, 'detail' => null];
        }

        $base = $excludedFees ? ' (fare before fees)' : ' (the trip fare)';

        if ($plan === null) {
            return new Split($currency, $fare, $commissionable, $tolls, null, $gross, [], null, null, null, $isEstimate, $lines, $money($commissionable).$base);
        }

        $commission = IntegerMath::add(IntegerMath::divide(IntegerMath::multiply($commissionable, $plan->percent_bp), 10_000, RoundingMode::HalfUp), $plan->fixed_minor);
        $limit = null;

        if ($plan->min_commission_minor !== null && $commission < $plan->min_commission_minor) {
            $commission = $plan->min_commission_minor;
            $limit = 'minimum commission '.$money($plan->min_commission_minor).' applies';
        }

        if ($plan->max_commission_minor !== null && $commission > $plan->max_commission_minor) {
            $commission = $plan->max_commission_minor;
            $limit = 'capped at '.$money($plan->max_commission_minor);
        }

        if ($commission > $commissionable) {
            $limit = 'never more than the trip fare';
        }

        $commission = max(0, min($commission, $commissionable));
        $percent = rtrim(rtrim(MoneyFormatter::decimal($plan->percent_bp, 2), '0'), '.').'%';
        $detail = $percent.' of '.$money($commissionable).$base
            .($plan->fixed_minor > 0 ? ' + '.$money($plan->fixed_minor) : '')
            .($limit !== null ? '; '.$limit : '');
        $deductions = [['code' => 'commission', 'label' => 'Platform commission', 'amount_minor' => $commission, 'detail' => $detail, 'base_minor' => $commissionable, 'percent_bp' => $plan->percent_bp]];
        $lines[] = ['code' => 'commission', 'label' => 'Platform commission', 'amount_minor' => -$commission, 'detail' => $detail];
        $net = IntegerMath::subtract($gross, $commission);

        return new Split($currency, $fare, $commissionable, $tolls, $commission, $gross, $deductions, $net, IntegerMath::subtract($fare, $net), $plan, $isEstimate, $lines, $money($commissionable).$base);
    }

    /**
     * @param  list<string>  $codes
     */
    private static function matches(string $code, array $codes): bool
    {
        foreach ($codes as $c) {
            if ($code === $c || str_starts_with($code, $c.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store the actual split on the ride's fare snapshot (called after finalisation and after operator adjustments).
     */
    public function snapshot(Ride $ride): ?Split
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();
        $arrangement = DriverArrangement::forRide($ride);

        if ($fare === null || ! $fare->isFinalised() || $arrangement === null) {
            return null;
        }

        $split = $this->split(FinalFare::fromRideFare($fare), $arrangement);
        $fare->forceFill(['commission_plan_id' => $split->plan?->id, 'earnings' => $split->toArray()])->save();

        return $split;
    }
}
