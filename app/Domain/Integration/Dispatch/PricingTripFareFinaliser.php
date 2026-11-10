<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\TripFareFinaliser;
use App\Domain\Dispatch\Data\FinalFare;
use App\Domain\Dispatch\Seams\AgreedFareFinaliser;
use App\Domain\Geo\Distance\DistanceMeter;
use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Finalisation\FareFinaliser;
use App\Domain\Pricing\Finalisation\TripActuals;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Rides\Models\RideEvent;
use App\Models\Ride;

/**
 * Dispatch's TripFareFinaliser over pricing's FareFinaliser (D-702). At completion:
 *  - billable distance comes from geo's DistanceMeter (filtered GPS trail, gaps re-routed or flagged);
 *  - duration is pickup (started_at) to now, waiting is arrival to start;
 *  - passenger changes (destination, stops) come from the ride timeline ("trip_changed" events);
 * then the pricing tolerance rules decide (quoted fare stands within tolerance; every change carries a reason).
 * Rides without a pricing snapshot (made before R-46 or by a test double) keep dispatch's agreed-fare rule.
 * FareFinaliser is idempotent, so the state machine's guard and its completion effect get the same fare.
 */
class PricingTripFareFinaliser implements TripFareFinaliser
{
    public const CHANGE_EVENT = 'trip_changed';

    public function __construct(
        private readonly FareFinaliser $finaliser,
        private readonly DistanceMeter $meter,
        private readonly AgreedFareFinaliser $fallback,
    ) {}

    public function finalise(Ride $ride): ?FinalFare
    {
        if (! RideFare::query()->where('ride_id', $ride->id)->exists()) {
            return $this->fallback->finalise($ride);
        }

        try {
            $fare = $this->finaliser->finalise($ride, $this->actuals($ride));
        } catch (FareNotFinalisable) {
            return $this->fallback->finalise($ride);
        }

        return new FinalFare($fare->total()->amountMinor, $fare->total()->currency, $fare->basis);
    }

    public function actuals(Ride $ride): TripActuals
    {
        $end = $ride->completed_at ?? now();
        $duration = $ride->started_at !== null ? max(0, (int) $ride->started_at->diffInSeconds($end, true)) : 0;
        $waiting = $ride->arrived_at !== null && $ride->started_at !== null ? max(0, (int) $ride->arrived_at->diffInSeconds($ride->started_at, true)) : 0;
        $changes = RideEvent::query()->where('ride_id', $ride->id)->where('type', self::CHANGE_EVENT)->pluck('reason_code')->all();

        return TripActuals::fromTripDistance(
            $this->meter->actual($ride),
            $duration,
            $waiting,
            null,
            in_array('destination_changed', $changes, true),
            in_array('stop_added', $changes, true),
        );
    }
}
