<?php

namespace App\Domain\Pricing\Finalisation;

use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\DiscountTerms;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareComponent;
use App\Domain\Pricing\Engine\FareEngine;
use App\Domain\Pricing\Engine\FareInputs;
use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\MoneyFormatter;
use App\Domain\Pricing\Money\RoundingMode;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Support\Facades\DB;

/**
 * Turns the quoted fare into the charged fare (docs/R46_PRICING.md "Finalisation"). Rules, in order:
 *
 *  1. The quoted fare stands while billable distance and duration stay within the rule set's tolerances
 *     (default +-15 % distance, +25 % duration) and the passenger changed nothing.
 *  2. The fare is recalculated with the SAME rule-set version, demand multiplier, zones and promotion amount when the
 *     passenger changed the trip (destination, stops, ended early), or the trip ran longer than the tolerance, or it
 *     was shorter than the tolerance (the passenger then pays less).
 *  3. Without a passenger change, a recalculated fare may rise at most overrun_cap_bp (default 20 %) above the quote.
 *  4. Waiting time beyond the free window and tolls actually paid (pass-through rule sets) are added in every case.
 *  5. An untrustworthy distance (geo quality "insufficient" or an unresolved gap) never moves the fare: the quote
 *     stands, waiting and tolls still apply.
 *
 * Every difference from the quote is an adjustment with a reason code and plain text; their amounts sum to
 * final - quoted, so a fare never changes silently. Idempotent: a finalised fare is returned as it is.
 */
final class FareFinaliser
{
    public function __construct(private FareEngine $engine, private EarningsSplitter $earnings) {}

    public function finalise(Ride $ride, TripActuals $actuals): FinalFare
    {
        return DB::transaction(function () use ($ride, $actuals) {
            $fare = RideFare::query()->where('ride_id', $ride->id)->lockForUpdate()->first();

            if ($fare === null) {
                throw new FareNotFinalisable('no_snapshot', 'This ride has no quoted fare snapshot (it predates R-46 pricing); its recorded fare stays as it is.');
            }

            if ($fare->isFinalised()) {
                return FinalFare::fromRideFare($fare);
            }

            $rules = PricingRuleSet::query()->findOrFail($fare->rule_set_id);
            [$breakdown, $basis, $adjustments] = $this->compute($fare, $rules, $actuals);

            $fare->forceFill([
                'final_components' => $breakdown->componentsArray(),
                'final_total_minor' => $breakdown->totalMinor(),
                'basis' => $basis,
                'adjustments' => array_map(fn (FareAdjustment $a) => $a->toArray(), $adjustments),
                'actual_distance_m' => max(0, $actuals->billableDistanceM),
                'actual_duration_s' => max(0, $actuals->durationS),
                'waiting_seconds' => max(0, $actuals->waitingSeconds),
                'distance_method' => substr($actuals->distanceMethod, 0, 32),
                'finalised_at' => now(),
            ])->save();

            // Mirror into the legacy decimal column that payments and history read (architecture section 0).
            $ride->forceFill(['final_fare' => $breakdown->total()->toDecimalString()])->save();

            AuditLog::record('pricing.fare_finalised', null, $ride, [
                'quoted_minor' => $fare->estimated_total_minor,
                'final_minor' => $fare->final_total_minor,
                'currency' => $fare->currency,
                'basis' => $basis,
                'adjustment_codes' => array_map(fn (FareAdjustment $a) => $a->code, $adjustments),
                'rule_set_id' => $fare->rule_set_id,
                'rule_version' => $fare->rule_version,
            ]);

            if ($ride->driver_id !== null) {
                $this->earnings->snapshot($ride);
            }

            return FinalFare::fromRideFare($fare->fresh());
        });
    }

    /**
     * Pure part of finalisation (no writes), exposed for tests and the admin simulator.
     *
     * @return array{0: FareBreakdown, 1: string, 2: list<FareAdjustment>}
     */
    public function compute(RideFare $fare, PricingRuleSet $rules, TripActuals $actuals): array
    {
        $quotedDistance = (int) $fare->quoted_distance_m;
        $quotedDuration = (int) $fare->quoted_duration_s;
        $quotedTotal = $fare->estimated_total_minor;
        $quoted = FareBreakdown::fromComponentsArray($fare->estimated_components, $fare->currency, $fare->demand_multiplier_bp);
        $trustworthy = $actuals->distanceIsTrustworthy();

        $longer = ($trustworthy && $actuals->billableDistanceM > self::scaled($quotedDistance, 10_000 + $rules->distance_tolerance_bp))
            || $actuals->durationS > self::scaled($quotedDuration, 10_000 + $rules->duration_tolerance_bp);
        $shorter = $trustworthy && $actuals->billableDistanceM < self::scaled($quotedDistance, max(0, 10_000 - $rules->distance_tolerance_bp));
        $passengerChange = $actuals->passengerChangedTheTrip() && $trustworthy;
        $recalculate = $passengerChange || ($trustworthy && ($longer || $shorter));

        $distance = $recalculate ? max(0, $actuals->billableDistanceM) : $quotedDistance;
        $duration = $recalculate ? max(0, $actuals->durationS) : $quotedDuration;

        $zoneCodes = $fare->zone_codes ?? [];
        $pickupZones = self::zones($zoneCodes['pickup'] ?? []);
        $dropoffZones = self::zones($actuals->destinationChangedByPassenger && $actuals->dropoffFeeCodes !== null ? $actuals->dropoffFeeCodes : ($zoneCodes['dropoff'] ?? []));

        $quotedTolls = $quoted->amountFor('tolls');
        $actualTolls = $rules->toll_mode === 'pass_through' && $actuals->tollsMinor !== null ? max(0, $actuals->tollsMinor) : null;
        $promotionComponent = collect($quoted->components)->firstWhere('code', 'promotion');
        $promotionLabel = $promotionComponent instanceof FareComponent ? $promotionComponent->label : 'Promotion';
        $discount = $fare->promotion_discount_minor > 0 ? DiscountTerms::fixed($promotionLabel, $fare->promotion_discount_minor) : null;

        $breakdown = $this->engine->compute($rules, new FareInputs(
            $distance,
            $duration,
            $pickupZones,
            $dropoffZones,
            $fare->demand_multiplier_bp,
            $actualTolls ?? ($quotedTolls > 0 ? $quotedTolls : null),
            $actualTolls !== null,
            max(0, $actuals->waitingSeconds),
            $discount,
        ));

        $waiting = $breakdown->amountFor('waiting');
        $tollsDelta = $actualTolls !== null ? $actualTolls - $quotedTolls : 0;

        // Rule 3: cap an increase nobody asked for.
        if ($recalculate && ! $passengerChange) {
            $limit = IntegerMath::add(IntegerMath::add($quotedTotal, IntegerMath::divide(IntegerMath::multiply($quotedTotal, $rules->overrun_cap_bp), 10_000, RoundingMode::Down)), IntegerMath::add($waiting, max(0, $tollsDelta)));
            $limit = IntegerMath::roundToMultiple($limit, max(1, $rules->rounding_unit_minor), RoundingMode::Down);

            if ($breakdown->totalMinor() > $limit) {
                $breakdown = new FareBreakdown(
                    [...$breakdown->components, new FareComponent('recalculation_cap', 'Increase capped', $limit - $breakdown->totalMinor(), 'Fare rises at most '.MoneyFormatter::decimal($rules->overrun_cap_bp, 2).'% above the quote unless you changed the trip')],
                    $breakdown->currency, $breakdown->demandMultiplierBp, $breakdown->taxIncludedMinor, $breakdown->notes,
                );
            }
        }

        $adjustments = [];
        $currency = $fare->currency;

        if ($waiting > 0) {
            $minutes = FareEngine::billableWaitMinutes($actuals->waitingSeconds, $rules->wait_free_minutes);
            $adjustments[] = new FareAdjustment('waiting_time', "Waiting time: {$minutes} min after the {$rules->wait_free_minutes} free minutes at pickup.", $waiting);
        }

        if ($tollsDelta !== 0) {
            $adjustments[] = new FareAdjustment('tolls', 'Tolls paid on your route: '.Money::of($actualTolls, $currency)->format().($quotedTolls > 0 ? ' (the quote estimated '.Money::of($quotedTolls, $currency)->format().').' : '.'), $tollsDelta);
        }

        $residual = $breakdown->totalMinor() - $quotedTotal - $waiting - $tollsDelta;

        if ($recalculate) {
            [$code, $text] = match (true) {
                $actuals->destinationChangedByPassenger => ['destination_changed', 'You changed the destination during the trip, so the fare was recalculated for the route driven'],
                $actuals->stopsAddedByPassenger => ['stops_added', 'You added a stop, so the fare was recalculated for the route driven'],
                $actuals->endedEarlyByPassenger => ['ended_early', 'You ended the trip early, so the fare was recalculated for the distance driven'],
                $shorter => ['route_shorter', 'The trip was shorter than quoted, so the fare was recalculated down'],
                default => ['route_longer', 'The trip ran well beyond the quoted distance or time (more than '.MoneyFormatter::decimal($rules->distance_tolerance_bp, 2).'% / '.MoneyFormatter::decimal($rules->duration_tolerance_bp, 2).'%), so the fare was recalculated; the increase is capped at '.MoneyFormatter::decimal($rules->overrun_cap_bp, 2).'%'],
            };
            $adjustments[] = new FareAdjustment($code, $text.' ('.FareEngine::km($distance).', '.FareEngine::minutes($duration).').', $residual);
            $basis = 'recalculated';
        } else {
            if ($residual !== 0) {
                // Only waiting/tolls should move a standing quote; anything else (e.g. a minimum-fare interaction) is shown.
                $adjustments[] = new FareAdjustment('fare_rules', 'Fare rules applied to the extras above (minimum fare and rounding).', $residual);
            }

            $note = $trustworthy
                ? 'Your quoted fare applied: the trip stayed within the quoted distance and time.'
                : 'Your quoted fare applied: the trip\'s distance could not be measured reliably, so it was not used.';
            $adjustments = [new FareAdjustment('quoted_fare_stands', $note, 0), ...$adjustments];
            $basis = 'quoted_fare_stands';
        }

        return [$breakdown, $basis, $adjustments];
    }

    private static function scaled(int $value, int $basisPoints): int
    {
        return IntegerMath::divide(IntegerMath::multiply($value, $basisPoints), 10_000, RoundingMode::Down);
    }

    /**
     * @param  list<string>  $codes
     * @return list<ZoneRef>
     */
    private static function zones(array $codes): array
    {
        return array_map(fn (string $code) => new ZoneRef(0, $code, 'snapshot', $code), $codes);
    }
}
