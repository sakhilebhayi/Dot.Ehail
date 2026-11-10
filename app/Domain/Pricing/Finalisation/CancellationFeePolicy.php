<?php

namespace App\Domain\Pricing\Finalisation;

use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareComponent;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Models\PromotionRedemption;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Promotions\PromotionRedeemer;
use App\Enums\RideActor;
use App\Models\AuditLog;
use App\Models\Ride;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation fees from the ride's own rule-set version (the one it was quoted under):
 *  - only a passenger cancellation can cost anything; drivers and operators never trigger a passenger fee;
 *  - free before a driver is assigned, and free for cancel_free_minutes after assignment;
 *  - after that, cancel_fee_minor;
 *  - once the driver has arrived and waited the free waiting time, no_show_fee_minor instead.
 * The assignment and arrival moments are read from the ride (driver_assigned_at or accepted_at; driver_arrived_at or
 * arrived_at) unless the caller passes them, since those columns belong to the dispatch domain.
 */
final class CancellationFeePolicy
{
    public function __construct(private PromotionRedeemer $promotions) {}

    public function feeFor(Ride $ride, RideActor|string $actor, ?CarbonInterface $at = null, ?CarbonInterface $driverArrivedAt = null, ?CarbonInterface $driverAssignedAt = null): ?Money
    {
        $decision = $this->decide($ride, $actor, $at, $driverArrivedAt, $driverAssignedAt);

        return $decision->charges() ? $decision->fee : null;
    }

    public function decide(Ride $ride, RideActor|string $actor, ?CarbonInterface $at = null, ?CarbonInterface $driverArrivedAt = null, ?CarbonInterface $driverAssignedAt = null): CancellationDecision
    {
        $at ??= now();
        $actorValue = $actor instanceof RideActor ? $actor->value : $actor;

        if ($actorValue !== RideActor::Passenger->value) {
            return new CancellationDecision(null, 'no_fee_not_passenger', 'No cancellation fee: the ride was not cancelled by the passenger.');
        }

        if (in_array($ride->status, ['in_progress', 'completed'], true)) {
            return new CancellationDecision(null, 'trip_not_cancellable', 'A trip that has started is completed, not cancelled.');
        }

        $fare = RideFare::query()->where('ride_id', $ride->id)->first();
        $rules = $fare === null ? null : PricingRuleSet::query()->find($fare->rule_set_id);

        if ($rules === null) {
            return new CancellationDecision(null, 'no_snapshot', 'No cancellation fee: this ride has no fare rules on record.');
        }

        $assignedAt = $driverAssignedAt ?? $ride->getAttribute('driver_assigned_at') ?? $ride->accepted_at;

        if ($ride->driver_id === null || $assignedAt === null) {
            return new CancellationDecision(null, 'before_driver_assigned', 'Free cancellation: no driver had been assigned yet.');
        }

        $arrivedAt = $driverArrivedAt ?? $ride->getAttribute('driver_arrived_at') ?? $ride->getAttribute('arrived_at');
        $arrivedAt = is_string($arrivedAt) ? Carbon::parse($arrivedAt) : $arrivedAt;
        $assignedAt = is_string($assignedAt) ? Carbon::parse($assignedAt) : $assignedAt;

        if ($arrivedAt !== null && $arrivedAt->copy()->addMinutes($rules->wait_free_minutes)->lessThanOrEqualTo($at)) {
            return $rules->no_show_fee_minor > 0
                ? new CancellationDecision(Money::of($rules->no_show_fee_minor, $rules->currency), 'no_show_fee', "The driver waited at pickup for the free {$rules->wait_free_minutes} minutes, so the no-show fee applies.")
                : new CancellationDecision(null, 'no_fee_configured', 'No fee is set for this case.');
        }

        if ($assignedAt->copy()->addMinutes($rules->cancel_free_minutes)->greaterThanOrEqualTo($at)) {
            return new CancellationDecision(null, 'within_free_window', "Free cancellation: within {$rules->cancel_free_minutes} minutes of a driver being assigned.");
        }

        return $rules->cancel_fee_minor > 0
            ? new CancellationDecision(Money::of($rules->cancel_fee_minor, $rules->currency), 'cancellation_fee', "Cancelled more than {$rules->cancel_free_minutes} minutes after a driver was assigned, so the cancellation fee applies.")
            : new CancellationDecision(null, 'no_fee_configured', 'No fee is set for this case.');
    }

    /**
     * Record the outcome of a cancellation on the fare snapshot: a fee becomes the final fare (basis
     * cancellation_fee); either way the quote's promotion is given back. Charging is the money domain's job.
     */
    public function settle(Ride $ride, CancellationDecision $decision): void
    {
        DB::transaction(function () use ($ride, $decision) {
            $fare = RideFare::query()->where('ride_id', $ride->id)->lockForUpdate()->first();

            if ($fare === null || $fare->isFinalised()) {
                return;
            }

            if ($fare->promotion_redemption_id !== null && ($redemption = PromotionRedemption::query()->find($fare->promotion_redemption_id)) !== null) {
                $this->promotions->reverse($redemption);
            }

            $components = $decision->charges() ? [new FareComponent($decision->code, $decision->code === 'no_show_fee' ? 'No-show fee' : 'Cancellation fee', $decision->fee->amountMinor, $decision->text)] : [];
            $breakdown = new FareBreakdown($components, $fare->currency);

            $fare->forceFill([
                'final_components' => $breakdown->componentsArray(),
                'final_total_minor' => $breakdown->totalMinor(),
                'basis' => 'cancellation_fee',
                'adjustments' => [(new FareAdjustment($decision->code, $decision->text, $breakdown->totalMinor() - $fare->estimated_total_minor))->toArray()],
                'finalised_at' => now(),
            ])->save();

            AuditLog::record('pricing.cancellation_settled', null, $ride, ['code' => $decision->code, 'fee_minor' => $breakdown->totalMinor(), 'currency' => $fare->currency]);
        });
    }
}
