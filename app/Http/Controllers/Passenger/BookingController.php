<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Geo\Support\GeoClientConfig;
use App\Domain\Passenger\Models\SavedPlace;
use App\Domain\Passenger\PlaceLabel;
use App\Domain\Passenger\ScheduleRules;
use App\Domain\Payments\PaymentDueGuard;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The passenger's home (/ride): map-first booking. A passenger with a ride in progress is taken to that ride
 * instead, so reopening the app always lands on what is happening now.
 */
class BookingController extends Controller
{
    public function show(Request $request, GeoClientConfig $geo, PaymentDueGuard $due): View|RedirectResponse
    {
        $user = $request->user();
        $active = Ride::query()->where('active_passenger_id', $user->id)->first();

        if ($active !== null && ! $request->boolean('new')) {
            return redirect()->route('ride.trip', $active);
        }

        // "Book again": the pickup and drop-off of one of the passenger's own past rides.
        $again = null;

        if ($request->filled('again')) {
            $past = Ride::query()->where('passenger_id', $user->id)->find((int) $request->query('again'));

            if ($past !== null && $past->pickup_lat !== null && $past->dropoff_lat !== null) {
                $again = [
                    'pickup' => ['label' => (string) $past->pickup_address, 'lat' => (float) $past->pickup_lat, 'lng' => (float) $past->pickup_lng],
                    'dropoff' => ['label' => (string) $past->dropoff_address, 'lat' => (float) $past->dropoff_lat, 'lng' => (float) $past->dropoff_lng],
                ];
            }
        }

        $places = SavedPlace::query()->where('user_id', $user->id)->orderByRaw("case kind when 'home' then 0 when 'work' then 1 else 2 end")->orderBy('label')->get()->map->toClientArray()->values();

        return view('passenger.booking', [
            'geoConfig' => $geo->toArray(),
            'places' => $places,
            'recent' => $this->recentDestinations($user->id, $places->pluck('address')->all()),
            'paymentDue' => $due->statusFor($user),
            'activeRide' => $active,
            'again' => $again,
            'schedule' => ScheduleRules::toArray(),
        ]);
    }

    /**
     * Where this passenger went lately: up to four distinct destinations of their own completed rides (newest
     * first), skipping saved places. Coordinates come from the ride rows (null once the privacy purge has run, and
     * then the place is left out). The passenger's own history only; nothing is shared or stored anew.
     *
     * @param  list<string>  $saved
     * @return \Illuminate\Support\Collection<int, array{name: string, detail: string, label: string, lat: float, lng: float}>
     */
    private function recentDestinations(int $userId, array $saved)
    {
        return Ride::query()
            ->where('passenger_id', $userId)
            ->where('status', 'completed')
            ->whereNotNull('dropoff_lat')
            ->whereNotNull('dropoff_lng')
            ->latest('id')
            ->limit(20)
            ->get(['dropoff_address', 'dropoff_lat', 'dropoff_lng'])
            ->unique(fn (Ride $ride) => mb_strtolower(trim((string) $ride->dropoff_address)))
            ->reject(fn (Ride $ride) => in_array($ride->dropoff_address, $saved, true))
            ->take(4)
            ->map(fn (Ride $ride) => [
                ...array_intersect_key(PlaceLabel::split($ride->dropoff_address), ['name' => 1, 'detail' => 1]),
                'label' => (string) $ride->dropoff_address,
                'lat' => round((float) $ride->dropoff_lat, 6),
                'lng' => round((float) $ride->dropoff_lng, 6),
            ])
            ->values();
    }
}
