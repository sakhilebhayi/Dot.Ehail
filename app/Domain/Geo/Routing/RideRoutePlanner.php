<?php

namespace App\Domain\Geo\Routing;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\RideRoute;
use App\Models\Ride;

/**
 * Keeps a ride's planned road route. Pricing or dispatch call attach() with the route they quoted; when nothing
 * was attached, planFor() asks the RoutePlanner for pickup -> drop-off. Without a routing provider there is no
 * planned route and route-deviation checks are skipped (unusual-stop checks still run).
 */
class RideRoutePlanner
{
    public function __construct(private RoutePlanner $routes) {}

    public function attach(Ride $ride, RouteResult $route): RideRoute
    {
        return RideRoute::query()->updateOrCreate(['ride_id' => $ride->id], [
            'provider' => $route->provider,
            'planned_distance_m' => $route->distanceM,
            'planned_duration_s' => $route->durationS,
            'planned_polyline' => $route->polyline,
            'planned_at' => now(),
        ]);
    }

    public function planFor(Ride $ride): ?RideRoute
    {
        $existing = RideRoute::query()->where('ride_id', $ride->id)->first();

        if ($existing !== null && filled($existing->planned_polyline)) {
            return $existing;
        }

        if ($ride->pickup_lat === null || $ride->pickup_lng === null || $ride->dropoff_lat === null || $ride->dropoff_lng === null) {
            return null;
        }

        try {
            $route = $this->routes->route(
                LatLng::from($ride->pickup_lat, $ride->pickup_lng),
                LatLng::from($ride->dropoff_lat, $ride->dropoff_lng),
            );
        } catch (RoutingUnavailable) {
            return null;
        }

        return $this->attach($ride, $route);
    }
}
