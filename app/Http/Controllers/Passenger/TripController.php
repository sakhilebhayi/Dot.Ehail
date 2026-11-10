<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Dispatch\RideFeed;
use App\Domain\Geo\Support\GeoClientConfig;
use App\Domain\Passenger\RideOverview;
use App\Domain\Passenger\ScheduleRules;
use App\Domain\Payments\Models\Receipt;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trips: the history (with receipts) and scheduled rides, and one ride's live page. The live page renders the
 * ride's current state on the server (so a reload or a slow network shows the truth at once) and the feed client
 * keeps it current.
 */
class TripController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $rides = Ride::query()->where('passenger_id', $user->id)->orderByDesc('id')->limit(60)->get();
        $receipts = Receipt::query()->where('passenger_id', $user->id)->whereIn('ride_id', $rides->pluck('id'))->get()->keyBy('ride_id');

        [$scheduled, $history] = $rides->partition(fn (Ride $ride) => $ride->statusEnum() === RideStatus::Scheduled);
        $active = $history->first(fn (Ride $ride) => $ride->statusEnum()->isActiveForPassenger());

        return view('passenger.trips', [
            'scheduled' => $scheduled->sortBy('scheduled_for')->values(),
            'active' => $active,
            'history' => $history->reject(fn (Ride $ride) => $active !== null && $ride->is($active))->values(),
            'receipts' => $receipts,
            'schedule' => ScheduleRules::toArray(),
        ]);
    }

    public function show(Request $request, Ride $ride, RideFeed $feed, RideOverview $overview, GeoClientConfig $geo): View
    {
        // Somebody else's ride does not exist as far as this person is concerned.
        abort_unless($ride->passenger_id === $request->user()->id, 404);

        return view('passenger.trip', [
            'ride' => $ride,
            'feed' => $feed->forPassenger($ride)['body'],
            'overview' => $overview->for($ride, $request->user()),
            'geoConfig' => $geo->toArray(),
            'schedule' => ScheduleRules::toArray(),
        ]);
    }
}
