<?php

namespace App\Domain\Integration\Money;

use App\Domain\Payments\Seams\FareBreakdown;
use App\Domain\Payments\Seams\FareSource;
use App\Domain\Payments\Seams\LegacyFareSource;
use App\Domain\Pricing\Earnings\DriverArrangement;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown as PricingBreakdown;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;

/**
 * Money reads a completed trip's fare from pricing's finalised snapshot (ride_fares), D-708:
 *   payable   = final_total_minor (what pricing charged, after the promotion)
 *   promotion = the platform-funded discount (positive), gross = payable + promotion
 *   tax       = the exclusive tax line (inclusive tax is inside the fare and not split out)
 *   platform fees = gross - tax - commissionable trip fare - tolls (booking and zone fees: not commissioned, D-308)
 * so money's identity gross = tax + platform fees + commission + driver net holds with pricing's split.
 * A ride without a finalised pricing snapshot (pre-R-46, operator-entered fare) keeps money's legacy reading
 * of rides.final_fare.
 */
class PricingFareSource implements FareSource
{
    public function __construct(private readonly LegacyFareSource $legacy, private readonly EarningsSplitter $splitter) {}

    public function finalFareFor(Ride $ride): ?FareBreakdown
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();

        if ($fare === null || ! $fare->isFinalised() || $fare->basis === 'cancellation_fee') {
            return $this->legacy->finalFareFor($ride);
        }

        $breakdown = PricingBreakdown::fromComponentsArray($fare->final_components ?? [], $fare->currency, $fare->demand_multiplier_bp);
        $promotion = max(0, -$breakdown->amountFor('promotion'));
        $payable = (int) $fare->final_total_minor;
        $gross = $payable + $promotion;
        $tax = max(0, $breakdown->amountFor('tax'));

        $arrangement = DriverArrangement::forRide($ride);
        $split = $arrangement === null ? null : $this->splitter->splitBreakdown($breakdown, $arrangement, $fare->finalised_at ?? now(), false);
        $platformFees = $split === null ? 0 : max(0, $gross - $tax - $split->commissionableMinor - $split->passThroughMinor);

        $components = array_values(array_filter(
            array_map(fn (array $c) => ['code' => (string) $c['code'], 'label' => (string) $c['label'], 'amount_minor' => (int) $c['amount_minor']], $fare->final_components ?? []),
            fn (array $c) => $c['code'] !== 'promotion' && $c['amount_minor'] !== 0,
        ));

        return new FareBreakdown(
            grossMinor: $gross,
            currency: $fare->currency,
            components: $components,
            promotionMinor: $promotion,
            taxMinor: $tax,
            platformFeesMinor: $platformFees,
            estimateMinor: $fare->estimated_total_minor,
            reasons: array_map(fn (array $a) => ['code' => (string) $a['code'], 'label' => (string) $a['text']], $fare->adjustments ?? []),
            distanceM: $fare->actual_distance_m,
            durationS: $fare->actual_duration_s,
            source: 'ride_fares',
        );
    }
}
