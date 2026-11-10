<?php

namespace App\Domain\Pricing\Finalisation;

use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareComponent;
use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Models\RideFare;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An operator sets a finalised fare to a new total (refund, goodwill, correction), always with a reason code and
 * text the passenger sees, appended to the fare's adjustment history and audited. The difference becomes a visible
 * "Adjusted by support" line, so the components still sum to the total.
 */
final class FareAdjuster
{
    public const REASON_CODES = ['refund_route_issue', 'refund_service_issue', 'goodwill', 'pricing_error', 'toll_correction', 'waiting_correction', 'other'];

    public function __construct(private EarningsSplitter $earnings) {}

    public function adjust(Ride $ride, User $operator, int $newTotalMinor, string $reasonCode, string $text): FinalFare
    {
        if ($newTotalMinor < 0) {
            throw new FareNotFinalisable('invalid_adjustment', 'A fare cannot be negative.');
        }

        if (! in_array($reasonCode, self::REASON_CODES, true) || mb_strlen(trim($text)) < 5) {
            throw new FareNotFinalisable('invalid_adjustment', 'An adjustment needs a known reason code and an explanation the passenger can read.');
        }

        return DB::transaction(function () use ($ride, $operator, $newTotalMinor, $reasonCode, $text) {
            $fare = RideFare::query()->where('ride_id', $ride->id)->lockForUpdate()->first();

            if ($fare === null || ! $fare->isFinalised()) {
                throw new FareNotFinalisable('not_finalised', 'Only a finalised fare can be adjusted.');
            }

            $previous = (int) $fare->final_total_minor;
            $delta = $newTotalMinor - $previous;

            if ($delta === 0) {
                return FinalFare::fromRideFare($fare);
            }

            $breakdown = FareBreakdown::fromComponentsArray($fare->final_components ?? [], $fare->currency, $fare->demand_multiplier_bp);
            $components = [...$breakdown->components, new FareComponent('operator_adjustment', 'Adjusted by support', $delta, trim($text))];
            $adjusted = new FareBreakdown($components, $fare->currency, $fare->demand_multiplier_bp);

            $fare->forceFill([
                'final_components' => $adjusted->componentsArray(),
                'final_total_minor' => $adjusted->totalMinor(),
                'basis' => 'operator_adjusted',
                'adjustments' => [...($fare->adjustments ?? []), (new FareAdjustment('operator:'.$reasonCode, trim($text), $delta, $operator->id, now()->toIso8601String()))->toArray()],
            ])->save();

            $ride->forceFill(['final_fare' => $adjusted->total()->toDecimalString()])->save();

            if ($fare->earnings !== null) {
                $this->earnings->snapshot($ride->fresh());
            }

            AuditLog::record('pricing.fare_adjusted_by_operator', $operator, $ride, [
                'previous_minor' => $previous,
                'new_minor' => $newTotalMinor,
                'currency' => $fare->currency,
                'reason_code' => $reasonCode,
                'text' => trim($text),
            ]);

            return FinalFare::fromRideFare($fare->fresh());
        });
    }
}
