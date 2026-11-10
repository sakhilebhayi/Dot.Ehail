<?php

namespace App\Domain\Geo\Console;

use App\Domain\Geo\Distance\DistanceMeter;
use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\RideRoute;
use App\Domain\Geo\Models\RouteCacheEntry;
use App\Domain\Geo\Models\TripPoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Location retention (POPIA minimality), daily from the scheduler:
 *  - raw trip points of trips whose last point is older than the retention period are reduced to the trip's
 *    distance summary (kept on ride_routes) and deleted, unless the safety domain holds the ride;
 *  - latest-location rows of drivers silent for longer than their retention are deleted;
 *  - expired route cache rows are deleted.
 */
class PurgeGeoData extends Command
{
    protected $signature = 'ehail:geo-purge {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Apply location retention: summarise and delete old trip points, stale driver locations and expired route cache';

    public function handle(DistanceMeter $meter, TrailRetentionHold $hold): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays((int) config('ehail-geo.locations.trip_points_retention_days', 90));

        $rideIds = TripPoint::query()
            ->select('ride_id')
            ->groupBy('ride_id')
            ->havingRaw('MAX(recorded_at) < ?', [$cutoff])
            ->pluck('ride_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $held = $hold->held($rideIds);
        $purgeable = array_values(array_diff($rideIds, $held));
        $pointsDeleted = 0;

        foreach ($purgeable as $rideId) {
            $points = TripPoint::query()->where('ride_id', $rideId)->orderBy('recorded_at')->orderBy('id')->get();
            $summary = $meter->measure($points, rerouteGaps: false);

            if ($dry) {
                $pointsDeleted += $points->count();

                continue;
            }

            DB::transaction(function () use ($rideId, $summary, &$pointsDeleted): void {
                $route = RideRoute::query()->firstOrNew(['ride_id' => $rideId]);
                // A summary measured while the trail was complete (with road-routed gaps) is kept as it was.
                if ($route->travelled_m === null) {
                    $route->travelled_m = $summary->travelledM;
                    $route->billable_m = $summary->billableM;
                    $route->distance_method = $summary->method;
                    $route->distance_quality = $summary->quality;
                }
                $route->trail_purged_at = now();
                $route->save();

                $pointsDeleted += TripPoint::query()->where('ride_id', $rideId)->delete();
            });
        }

        $staleDrivers = DriverLocation::query()->where('recorded_at', '<', now()->subDays((int) config('ehail-geo.locations.driver_location_retention_days', 7)));
        $expiredRoutes = RouteCacheEntry::query()->where('expires_at', '<', now());

        $driverRows = $dry ? $staleDrivers->count() : $staleDrivers->delete();
        $routeRows = $dry ? $expiredRoutes->count() : $expiredRoutes->delete();

        $this->info(sprintf(
            '%s%d trips summarised (%d points), %d held by safety, %d stale driver locations, %d expired routes.',
            $dry ? '[dry run] ' : '',
            count($purgeable),
            $pointsDeleted,
            count($held),
            $driverRows,
            $routeRows,
        ));

        return self::SUCCESS;
    }
}
