<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\Ehail\AlreadyRated;
use App\Services\Ehail\RideRatings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RideRatingController extends Controller
{
    /**
     * The passenger rates the driver, or the driver rates the passenger, once
     * the ride is completed. RidePolicy::rate restricts this to those two.
     */
    public function store(Ride $ride, Request $request, RideRatings $ratings): RedirectResponse
    {
        Gate::authorize('rate', $ride);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $ratings->rate($ride, $request->user(), (int) $validated['rating'], $validated['comment'] ?? null);
        } catch (AlreadyRated $e) {
            return back()->withErrors(['rating' => $e->getMessage()]);
        }

        return redirect()->route('rides.show', $ride)->with('status', 'Thanks, your rating was saved.');
    }
}
