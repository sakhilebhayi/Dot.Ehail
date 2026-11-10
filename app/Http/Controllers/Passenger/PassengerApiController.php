<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Passenger\PickupEtas;
use App\Domain\Passenger\RideOverview;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The passenger app's own small JSON reads: the ride overview the feed does not carry, and pickup ETAs per
 * category for the compare cards. Coordinates arrive in the request body, never the query string (POPIA).
 */
class PassengerApiController extends Controller
{
    public function overview(Request $request, Ride $ride, RideOverview $overview): JsonResponse
    {
        abort_unless($ride->passenger_id === $request->user()->id, 404);

        return response()->json($overview->for($ride, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function pickupEtas(Request $request, PickupEtas $etas): JsonResponse
    {
        $valid = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'categories' => $etas->forPickup((float) $valid['lat'], (float) $valid['lng'], $request->user()),
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
