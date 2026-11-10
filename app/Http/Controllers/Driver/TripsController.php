<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Safety\Models\UserReport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use App\Http\Controllers\Driver\Support\TripSummary;
use App\Models\Ride;
use App\Models\RideRating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trip history and one trip's summary (final fare against the estimate, the earnings split, rating, report).
 * Only rides this driver drove; any other ride is a 404.
 */
class TripsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (DriverState::for($request->user())->profile() === null) {
            return redirect()->route('drive.apply');
        }

        $rides = Ride::query()
            ->where('driver_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(20, ['id', 'status', 'pickup_address', 'dropoff_address', 'quoted_total_minor', 'final_fare_minor', 'currency', 'requested_at', 'completed_at', 'cancelled_at', 'created_at', 'category_code', 'vehicle_type']);

        return view('driver.trips', ['rides' => $rides]);
    }

    public function show(Request $request, Ride $ride): View
    {
        abort_unless($ride->driver_id !== null && $ride->driver_id === $request->user()->id, 404);

        $myRating = RideRating::query()->where('ride_id', $ride->id)->where('rated_by', $request->user()->id)->first();

        return view('driver.trip', [
            'ride' => $ride,
            'summary' => TripSummary::for($ride),
            'myRating' => $myRating,
            'canRate' => $ride->statusEnum()->value === 'completed' && $myRating === null,
            'reportCategories' => array_values(array_filter(UserReport::CATEGORIES, fn (string $c) => ! in_array($c, ['vehicle_mismatch', 'driver_mismatch', 'unsafe_driving'], true))),
        ]);
    }
}
