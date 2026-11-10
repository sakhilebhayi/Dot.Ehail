<?php

namespace App\Domain\Safety;

use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\Models\RideRoute;
use App\Models\Ride;

/**
 * The line the public trip-share page draws (R-46 ppass, D-2011): the ride's planned road route with both ends cut
 * off, so the map shows which way the car is going without marking the door it left from or the one it is going to.
 * The page names the two areas in words; it never carries an address or the exact pickup or drop-off point.
 */
final class TripShareRoute
{
    /** Encoded polyline (precision 5), or null when no road route is stored or it is too short to trim. */
    public static function trimmed(Ride $ride): ?string
    {
        $points = RideRoute::query()->where('ride_id', $ride->id)->first()?->plannedPoints() ?? [];
        $trim = max(100, (int) config('ehail_safety.trip_share.route_trim_m', 300));

        if (count($points) < 2) {
            return null;
        }

        $total = Haversine::pathMetres($points);

        if ($total < $trim * 3) {
            return null;
        }

        $kept = [];
        $walked = 0.0;

        foreach ($points as $i => $point) {
            if ($i > 0) {
                $walked += Haversine::metres($points[$i - 1], $point);
            }

            if ($walked >= $trim && $walked <= $total - $trim) {
                $kept[] = $point;
            }
        }

        return count($kept) >= 2 ? Polyline::encode($kept) : null;
    }

    /** Changes when the destination or the planned route does, so an open page knows to load the new line. */
    public static function key(Ride $ride): string
    {
        $route = RideRoute::query()->where('ride_id', $ride->id)->first(['id', 'planned_at']);

        return substr(hash('sha256', implode('|', [$ride->id, $ride->dropoff_lat, $ride->dropoff_lng, $route?->id, $route?->planned_at?->getTimestamp()])), 0, 12);
    }
}
