<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\MoneyFormatter;
use App\Domain\Pricing\Money\RoundingMode;

/**
 * The fare formula (docs/R46_PRICING.md section "Formula"), pure and deterministic: same rule set + same inputs =
 * same breakdown, integers only.
 *
 *   trip      = base + per_km x km + per_minute x minutes + waiting (km and minutes in tenths, half up: D-1900)
 *   subtotal  = trip + booking_fee + zone fees + tolls
 *   minimum   = max(0, minimum - subtotal)                       (a visible "minimum fare" line)
 *   demand    = (subtotal + minimum - tolls) x (multiplier - 1)   (capped, only when enabled; tolls never uplifted)
 *   discount  = promotion, never more than everything above
 *   tax       = exclusive: rate x the discounted amount; inclusive: shown as "includes", not added
 *   rounding  = the difference to the rule set's rounding unit
 *   total     = sum of all lines
 */
final class FareEngine
{
    public function compute(PricingRuleSet $rules, FareInputs $in): FareBreakdown
    {
        $mode = $rules->roundingMode();
        $currency = $rules->currency;
        $components = [];
        $notes = [];

        $components[] = new FareComponent('base', 'Base fare', $rules->base_minor);

        // Metered lines bill the quantity they show (D-1900): distance in tenths of a km, time in tenths of a minute,
        // each line = rate x that quantity rounded to the cent, so "5.7 km x R7.50/km = R42.75" holds on the page.
        $kmTenths = self::kmTenths($in->distanceM);
        $components[] = new FareComponent('distance', 'Distance', FareComponent::meteredAmount($rules->per_km_minor, $kmTenths, $mode), FareComponent::quantityText($kmTenths).' km x '.self::money($rules->per_km_minor, $currency).'/km', $kmTenths, 'km', $rules->per_km_minor);

        $minuteTenths = self::minuteTenths($in->durationS);
        $components[] = new FareComponent('time', 'Time', FareComponent::meteredAmount($rules->per_minute_minor, $minuteTenths, $mode), FareComponent::quantityText($minuteTenths).' min x '.self::money($rules->per_minute_minor, $currency).'/min', $minuteTenths, 'min', $rules->per_minute_minor);

        $billableWaitMinutes = self::billableWaitMinutes($in->waitingSeconds, $rules->wait_free_minutes);

        if ($billableWaitMinutes > 0 && $rules->wait_per_minute_minor > 0) {
            $components[] = new FareComponent(
                'waiting',
                'Waiting time',
                IntegerMath::multiply($billableWaitMinutes, $rules->wait_per_minute_minor),
                $billableWaitMinutes.' min after '.$rules->wait_free_minutes.' free min x '.self::money($rules->wait_per_minute_minor, $currency).'/min',
                $billableWaitMinutes * 10,
                'min',
                $rules->wait_per_minute_minor,
            );
        }

        if ($rules->booking_fee_minor > 0) {
            $components[] = new FareComponent('booking_fee', 'Booking fee', $rules->booking_fee_minor);
        }

        foreach (['pickup' => $in->pickupZones, 'dropoff' => $in->dropoffZones] as $end => $zones) {
            $charged = [];

            foreach ($zones as $zone) {
                if ($zone->feeCode === null || isset($charged[$zone->feeCode])) {
                    continue;
                }

                $fee = $rules->zoneFee($zone->feeCode);
                $amount = $fee === null ? 0 : $fee[$end.'_minor'];

                if ($amount > 0) {
                    $charged[$zone->feeCode] = true;
                    $components[] = new FareComponent("zone_fee:{$zone->feeCode}:{$end}", $fee['label'], $amount, $end === 'pickup' ? 'Pickup in this zone' : 'Drop-off in this zone');
                }
            }
        }

        $tollsMinor = 0;

        if ($rules->toll_mode === 'pass_through') {
            if ($in->tollsMinor !== null && $in->tollsMinor > 0) {
                $tollsMinor = $in->tollsMinor;
                $components[] = new FareComponent('tolls', $in->tollsAreActual ? 'Tolls' : 'Tolls (estimate)', $tollsMinor, 'Passed on at cost');
            } elseif ($in->tollsMinor === null && ! $in->tollsAreActual) {
                $notes[] = 'Tolls on the route, if any, are added at cost after the trip.';
            }
        }

        $subtotal = self::sum($components);

        if ($subtotal < $rules->minimum_minor) {
            $components[] = new FareComponent('minimum_adjustment', 'Minimum fare', IntegerMath::subtract($rules->minimum_minor, $subtotal), 'Fares start at '.self::money($rules->minimum_minor, $currency));
        }

        $demandBp = $this->effectiveDemandBp($rules, $in->demandMultiplierBp);

        if ($demandBp > 10_000) {
            $upliftBase = IntegerMath::subtract(self::sum($components), $tollsMinor);
            $uplift = IntegerMath::divide(IntegerMath::multiply($upliftBase, $demandBp - 10_000), 10_000, $mode);

            if ($uplift > 0) {
                $components[] = new FareComponent('demand', 'Busy-time pricing', $uplift, MoneyFormatter::decimal($demandBp, 4).'x while demand is high (capped at '.MoneyFormatter::decimal($rules->demand_cap_bp, 4).'x)');
            }
        }

        if ($in->discount !== null) {
            $before = self::sum($components);
            $discount = $this->discountAmount($in->discount, $before, $mode);

            if ($discount > 0) {
                $components[] = new FareComponent('promotion', $in->discount->label, -$discount);
            }
        }

        $taxIncluded = 0;

        if ($rules->tax_mode === 'exclusive' && $rules->tax_rate_bp > 0) {
            $taxable = self::sum($components);
            $tax = IntegerMath::divide(IntegerMath::multiply($taxable, $rules->tax_rate_bp), 10_000, $mode);

            if ($tax > 0) {
                $components[] = new FareComponent('tax', $rules->tax_label ?: 'Tax', $tax, MoneyFormatter::decimal($rules->tax_rate_bp, 2).'%');
            }
        }

        $beforeRounding = self::sum($components);
        $rounded = IntegerMath::roundToMultiple($beforeRounding, max(1, $rules->rounding_unit_minor), $mode);

        if ($rounded !== $beforeRounding) {
            $components[] = new FareComponent('rounding', 'Rounding', IntegerMath::subtract($rounded, $beforeRounding), 'To the nearest '.self::money(max(1, $rules->rounding_unit_minor), $currency));
        }

        if ($rules->tax_mode === 'inclusive' && $rules->tax_rate_bp > 0) {
            $taxIncluded = IntegerMath::divide(IntegerMath::multiply($rounded, $rules->tax_rate_bp), 10_000 + $rules->tax_rate_bp, $mode);
            $notes[] = 'Includes '.($rules->tax_label ?: 'tax').' of '.self::money($taxIncluded, $currency).'.';
        }

        return new FareBreakdown($components, $currency, $demandBp, $taxIncluded, $notes);
    }

    /**
     * The multiplier actually applied: 1.0x unless the rule set enables demand pricing; never above its cap,
     * never below 1.0x.
     */
    public function effectiveDemandBp(PricingRuleSet $rules, int $signalBp): int
    {
        if (! $rules->demand_enabled) {
            return 10_000;
        }

        return max(10_000, min($signalBp, max(10_000, $rules->demand_cap_bp)));
    }

    public static function billableWaitMinutes(int $waitingSeconds, int $freeMinutes): int
    {
        $beyond = $waitingSeconds - $freeMinutes * 60;

        // Each started minute after the free window counts.
        return $beyond <= 0 ? 0 : IntegerMath::divide($beyond, 60, RoundingMode::Up);
    }

    private function discountAmount(DiscountTerms $terms, int $available, RoundingMode $mode): int
    {
        if ($available <= 0) {
            return 0;
        }

        $discount = $terms->percentBp !== null
            ? IntegerMath::divide(IntegerMath::multiply($available, $terms->percentBp), 10_000, $mode)
            : (int) $terms->amountMinor;

        if ($terms->capMinor !== null) {
            $discount = min($discount, $terms->capMinor);
        }

        return max(0, min($discount, $available));
    }

    /**
     * @param  list<FareComponent>  $components
     */
    private static function sum(array $components): int
    {
        return array_reduce($components, fn (int $s, FareComponent $c) => IntegerMath::add($s, $c->amountMinor), 0);
    }

    /** Billable distance: tenths of a km, half up (5 714 m -> 57 = 5.7 km). */
    public static function kmTenths(int $metres): int
    {
        return IntegerMath::divide(max(0, $metres), 100, RoundingMode::HalfUp);
    }

    /** Billable time: tenths of a minute, half up (686 s -> 114 = 11.4 min). */
    public static function minuteTenths(int $seconds): int
    {
        return IntegerMath::divide(max(0, $seconds), 6, RoundingMode::HalfUp);
    }

    public static function km(int $metres): string
    {
        return FareComponent::quantityText(self::kmTenths($metres)).' km';
    }

    public static function minutes(int $seconds): string
    {
        return FareComponent::quantityText(self::minuteTenths($seconds)).' min';
    }

    private static function money(int $minor, string $currency): string
    {
        return Money::of($minor, $currency)->format();
    }
}
