<?php

namespace App\Domain\Ops\Drivers;

use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Models\RideEvent;
use App\Domain\Safety\TrustSignals;
use App\Models\Ride;
use Illuminate\Support\Carbon;

/**
 * Factual driver statistics for operations, each counted from rows (never estimated):
 *   offers received = ride_offers sent to the driver in the window;
 *   acceptance = accepted / (accepted + declined + expired) offers (withdrawn offers are the system's, not the driver's);
 *   cancellations after accepting = rides moved to cancelled_by_driver by this driver in the window, and back-outs
 *     (ride_events from driver_assigned/arriving/arrived to searching with the driver as actor);
 *   completed trips = rides completed with this driver;
 *   rating band = safety's TrustSignals band (no decimals on sensitive surfaces; appeal-excluded ratings left out).
 */
class DriverFacts
{
    /** @return array<string, mixed> */
    public function for(int $driverId, ?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        $offers = RideOffer::query()->where('driver_id', $driverId)->where('sent_at', '>=', $since)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $answered = ($offers['accepted'] ?? 0) + ($offers['declined'] ?? 0) + ($offers['expired'] ?? 0);

        $driverCancels = Ride::query()->where('driver_id', $driverId)->where('status', 'cancelled_by_driver')->where('cancelled_at', '>=', $since)->count();
        $backOuts = RideEvent::query()
            ->where('actor_type', 'driver')->where('actor_id', $driverId)->where('type', 'transition')
            ->whereIn('from_status', ['driver_assigned', 'driver_arriving', 'driver_arrived'])
            ->where('to_status', 'searching')->where('occurred_at', '>=', $since)->count();

        $completedWindow = Ride::query()->where('driver_id', $driverId)->where('status', 'completed')->where('completed_at', '>=', $since)->count();
        $completedAll = Ride::query()->where('driver_id', $driverId)->where('status', 'completed')->count();
        $rating = TrustSignals::ratingStats($driverId, 'driver');

        return [
            'since' => $since,
            'offers_received' => array_sum($offers->all()),
            'offers_accepted' => $offers['accepted'] ?? 0,
            'offers_declined' => $offers['declined'] ?? 0,
            'offers_expired' => $offers['expired'] ?? 0,
            'acceptance_rate' => $answered > 0 ? round(100 * ($offers['accepted'] ?? 0) / $answered, 1) : null,
            'cancelled_after_accepting' => $driverCancels,
            'backed_out' => $backOuts,
            'completed_window' => $completedWindow,
            'completed_all' => $completedAll,
            'rating' => TrustSignals::band($rating['average'], $rating['count']),
        ];
    }
}
