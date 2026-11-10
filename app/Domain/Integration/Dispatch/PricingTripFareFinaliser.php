<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\TripFareFinaliser;
use App\Domain\Dispatch\Data\FinalFare;
use App\Domain\Dispatch\Geo;
use App\Domain\Dispatch\Seams\AgreedFareFinaliser;
use App\Domain\Geo\Distance\DistanceMeter;
use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Finalisation\FareFinaliser;
use App\Domain\Pricing\Finalisation\TripActuals;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Rides\Models\RideEvent;
use App\Models\Ride;
use Illuminate\Support\Collection;

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

    /**
     * A passenger's change lifts the overrun cap, so the measured distance is the only brake on the fare. A
     * driver's phone reports that distance; a spoofed trail could bill any length (R-46 security S-18). The
     * billable distance is therefore bounded by the changed trip itself: the straight-line path pickup -> each
     * added stop -> the final drop-off, times a generous road factor, and never below the quoted distance.
     *
     * @param  Collection<int, RideEvent>  $changeEvents
     */
    public function boundedByChangedRoute(Ride $ride, object $distance, $changeEvents): object
    {
        if ($ride->pickup_lat === null || $ride->dropoff_lat === null) {
            return $distance;
        }

        $points = [[(float) $ride->pickup_lat, (float) $ride->pickup_lng]];
        foreach ($changeEvents as $event) {
            $context = (array) $event->context;
            if ($event->reason_code === 'stop_added' && isset($context['lat'], $context['lng'])) {
                $points[] = [(float) $context['lat'], (float) $context['lng']];
            }
        }
        $points[] = [(float) $ride->dropoff_lat, (float) $ride->dropoff_lng];

        $crow = 0;
        for ($i = 1; $i < count($points); $i++) {
            $crow += Geo::distanceM($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        $quoted = (int) RideFare::query()->where('ride_id', $ride->id)->value('quoted_distance_m');
        $bound = max($quoted, (int) round($crow * (float) config('ehail_security.trip_change_road_factor', 2.0)));

        if ((int) $distance->billableM <= $bound) {
            return $distance;
        }

        return (object) [
            'billableM' => $bound,
            'method' => (string) $distance->method,
            'quality' => (string) $distance->quality,
            'flags' => [...array_values((array) $distance->flags), 'capped_to_changed_route'],
        ];
    }

    public function actuals(Ride $ride): TripActuals
    {
        $end = $ride->completed_at ?? now();
        $duration = $ride->started_at !== null ? max(0, (int) $ride->started_at->diffInSeconds($end, true)) : 0;
        $waiting = $ride->arrived_at !== null && $ride->started_at !== null ? max(0, (int) $ride->arrived_at->diffInSeconds($ride->started_at, true)) : 0;
        $changeEvents = RideEvent::query()->where('ride_id', $ride->id)->where('type', self::CHANGE_EVENT)->orderBy('id')->get(['reason_code', 'context']);
        $changes = $changeEvents->pluck('reason_code')->all();
        $distance = $this->meter->actual($ride);

        if ($changes !== []) {
            $distance = $this->boundedByChangedRoute($ride, $distance, $changeEvents);
        }

        return TripActuals::fromTripDistance(
            $distance,
            $duration,
            $waiting,
            null,
            in_array('destination_changed', $changes, true),
            in_array('stop_added', $changes, true),
        );
    }
}
