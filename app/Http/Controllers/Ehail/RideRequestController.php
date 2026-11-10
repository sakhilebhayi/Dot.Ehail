<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Pricing\Legacy\LegacyRideFareAdapter;
use App\Domain\Rides\TripPin;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The core loop this platform never actually had: RideController (the
 * existing ride search/detail pages) is read-only, and grepping the
 * codebase before this pass turned up nothing anywhere that ever created a
 * Ride -- the RideObserver/RideStatusUpdated/Reverb infrastructure landed
 * with no way to ever fire it. This controller is the request half; see
 * RideLifecycleController for accept/advance/cancel.
 */
class RideRequestController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', Ride::class);

        return view('rides.create');
    }

    public function store(Request $request, LegacyRideFareAdapter $legacyFares, TripPin $pins): RedirectResponse
    {
        Gate::authorize('create', Ride::class);

        $validated = $request->validate([
            'pickup_address' => ['required', 'string', 'max:255'],
            // A coordinate is only meaningful as a pair.
            'pickup_lat' => ['nullable', 'required_with:pickup_lng', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'required_with:pickup_lat', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'required_with:dropoff_lng', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'required_with:dropoff_lat', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['required', 'string', Rule::in(array_keys(config('ehail.tariff.multipliers')))],
        ]);

        // A field genuinely absent from the request (as opposed to present
        // but empty/null) is simply omitted from validate()'s return value,
        // not set to null -- these four are all optional, so every access
        // below uses ?? rather than trusting the key to exist.
        $pickupLat = $validated['pickup_lat'] ?? null;
        $pickupLng = $validated['pickup_lng'] ?? null;
        $dropoffLat = $validated['dropoff_lat'] ?? null;
        $dropoffLng = $validated['dropoff_lng'] ?? null;

        // R-46: the estimate comes from the pricing engine on a ROAD route (LegacyRideFareAdapter). Without both
        // coordinate pairs, a routing provider or a published tariff there is no estimate rather than a fabricated one.
        $estimate = $legacyFares->estimate($request->user(), $pickupLat, $pickupLng, $dropoffLat, $dropoffLng, $validated['vehicle_type']);

        $alreadyActive = fn () => back()->withInput()->withErrors(['pickup_address' => 'You already have a ride in progress. Finish or cancel it first.']);
        if (Ride::where('active_passenger_id', $request->user()->id)->exists()) {
            return $alreadyActive();
        }

        try {
            $ride = DB::transaction(function () use ($request, $validated, $pickupLat, $pickupLng, $dropoffLat, $dropoffLng, $estimate, $legacyFares, $pins) {
                $ride = Ride::create([
                    'passenger_id' => $request->user()->id,
                    'pickup_address' => $validated['pickup_address'],
                    'pickup_lat' => $pickupLat,
                    'pickup_lng' => $pickupLng,
                    'dropoff_address' => $validated['dropoff_address'],
                    'dropoff_lat' => $dropoffLat,
                    'dropoff_lng' => $dropoffLng,
                    'status' => 'requested',
                    'vehicle_type' => $validated['vehicle_type'],
                    'distance_km' => $estimate->distanceKm,
                    'estimated_fare' => $estimate->estimatedFare,
                    'requested_at' => now(),
                ]);

                // The trip PIN, as on the R-46 request path: without it a driver could start and bill a trip with no
                // passenger in the car (R-46 security S-12). The passenger reads it on the ride page.
                $ride->forceFill($pins->attributesFor($ride))->save();

                if ($estimate->quote !== null) {
                    $legacyFares->bind($estimate->quote, $ride, $request->user());
                }

                return $ride;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced past the check: the one-active-ride index refused the second (S-12).
            return $alreadyActive();
        }

        return redirect()->route('rides.show', $ride)->with('status', 'Ride requested. Looking for a driver…');
    }
}
