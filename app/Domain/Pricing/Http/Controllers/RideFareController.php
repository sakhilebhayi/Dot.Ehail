<?php

namespace App\Domain\Pricing\Http\Controllers;

use App\Domain\Pricing\Earnings\DriverArrangement;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Finalisation\FinalFare;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\Money;
use App\Enums\RideActor;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fare of one ride for the people entitled to it: the passenger sees the quote, the final fare and every change
 * with its reason; the driver also sees the earnings split (estimate before completion, actual after); operators see
 * both. Rides from before R-46 pricing show their recorded fares as they are, marked legacy.
 */
class RideFareController extends Controller
{
    public function show(Request $request, Ride $ride, EarningsSplitter $splitter): JsonResponse
    {
        $role = $ride->actorFor($request->user());
        abort_if($role === null, 403);

        $fare = RideFare::query()->where('ride_id', $ride->id)->first();

        if ($fare === null) {
            $currency = (string) config('ehail_pricing.default_currency');

            return response()->json([
                'legacy' => true,
                'note' => 'This ride was priced before itemised fares existed; its recorded fare is shown unchanged.',
                'estimated' => $ride->estimated_fare === null ? null : Money::fromDecimal((string) $ride->estimated_fare, $currency),
                'final' => $ride->final_fare === null ? null : Money::fromDecimal((string) $ride->final_fare, $currency),
            ]);
        }

        $payload = [
            'legacy' => false,
            'quoted' => FareBreakdown::fromComponentsArray($fare->estimated_components, $fare->currency, $fare->demand_multiplier_bp)->toArray(),
            'final' => $fare->isFinalised() ? FinalFare::fromRideFare($fare)->toPassengerArray() : null,
        ];

        if ($role === RideActor::Driver || $role === RideActor::Operator) {
            $split = null;

            if ($fare->isFinalised()) {
                $arrangement = DriverArrangement::forRide($ride);
                $split = $arrangement === null ? null : $splitter->split(FinalFare::fromRideFare($fare), $arrangement);
            } elseif ($ride->driver_id !== null || $role === RideActor::Driver) {
                $split = $splitter->estimateForRide($ride, $ride->driver_id ?? $request->user()->id);
            }

            $payload['earnings'] = $split?->toDriverArray();
        }

        return response()->json($payload);
    }
}
