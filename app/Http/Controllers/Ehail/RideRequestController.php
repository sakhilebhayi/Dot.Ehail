<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\Ehail\FareCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function store(Request $request, FareCalculator $fareCalculator): RedirectResponse
    {
        Gate::authorize('create', Ride::class);

        $validated = $request->validate([
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['required', 'string', 'in:economy,standard,premium,suv'],
        ]);

        // A field genuinely absent from the request (as opposed to present
        // but empty/null) is simply omitted from validate()'s return value,
        // not set to null -- these four are all optional, so every access
        // below uses ?? rather than trusting the key to exist.
        $pickupLat = $validated['pickup_lat'] ?? null;
        $pickupLng = $validated['pickup_lng'] ?? null;
        $dropoffLat = $validated['dropoff_lat'] ?? null;
        $dropoffLng = $validated['dropoff_lng'] ?? null;

        // Distance/fare are only computable when both endpoints carry real
        // coordinates -- an address-only request (no lat/lng) still gets
        // created, just without an estimate rather than a fabricated one.
        $distanceKm = null;
        $estimatedFare = null;

        $hasPickupCoords = $pickupLat !== null && $pickupLng !== null;
        $hasDropoffCoords = $dropoffLat !== null && $dropoffLng !== null;

        if ($hasPickupCoords && $hasDropoffCoords) {
            $distanceKm = $fareCalculator->distanceKm(
                (float) $pickupLat, (float) $pickupLng,
                (float) $dropoffLat, (float) $dropoffLng,
            );
            $estimatedFare = $fareCalculator->estimate($distanceKm, $validated['vehicle_type']);
        }

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
            'distance_km' => $distanceKm,
            'estimated_fare' => $estimatedFare,
            'requested_at' => now(),
        ]);

        return redirect()->route('rides.show', $ride)->with('status', 'Ride requested. Looking for a driver…');
    }
}
