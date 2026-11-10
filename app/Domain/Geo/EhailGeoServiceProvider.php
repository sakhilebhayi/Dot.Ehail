<?php

namespace App\Domain\Geo;

use App\Domain\Geo\Console\PurgeGeoData;
use App\Domain\Geo\Console\SeedReferenceAreas;
use App\Domain\Geo\Geocoding\Drivers\GoogleGeocoder;
use App\Domain\Geo\Geocoding\Drivers\MapboxGeocoder;
use App\Domain\Geo\Geocoding\Drivers\NominatimGeocoder;
use App\Domain\Geo\Geocoding\Drivers\NullGeocoder;
use App\Domain\Geo\Geocoding\Drivers\OrsGeocoder;
use App\Domain\Geo\Geocoding\Drivers\PhotonGeocoder;
use App\Domain\Geo\Geocoding\GeocodingDriver;
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\NoTrailRetentionHold;
use App\Domain\Geo\Locations\ProfileDriverPresence;
use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Geo\Routing\Drivers\GoogleRoutesDriver;
use App\Domain\Geo\Routing\Drivers\MapboxDirectionsDriver;
use App\Domain\Geo\Routing\Drivers\NullRoutingDriver;
use App\Domain\Geo\Routing\Drivers\OrsDirectionsDriver;
use App\Domain\Geo\Routing\Drivers\OsrmDriver;
use App\Domain\Geo\Routing\Drivers\ValhallaDriver;
use App\Domain\Geo\Routing\RoutingDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 geo domain wiring: provider drivers chosen by config, the dispatch seam, rate limiters and the retention
 * schedule. Limiters live here, never in route files (route:cache).
 */
class EhailGeoServiceProvider extends ServiceProvider
{
    public const ROUTING_DRIVERS = [
        'google' => GoogleRoutesDriver::class,
        'mapbox' => MapboxDirectionsDriver::class,
        'ors' => OrsDirectionsDriver::class,
        'osrm' => OsrmDriver::class,
        'valhalla' => ValhallaDriver::class,
        'none' => NullRoutingDriver::class,
    ];

    public const GEOCODING_DRIVERS = [
        'google' => GoogleGeocoder::class,
        'mapbox' => MapboxGeocoder::class,
        'ors' => OrsGeocoder::class,
        'photon' => PhotonGeocoder::class,
        'nominatim' => NominatimGeocoder::class,
        'none' => NullGeocoder::class,
    ];

    public function register(): void
    {
        $this->app->bind(RoutingDriver::class, function ($app) {
            $class = self::ROUTING_DRIVERS[(string) config('ehail-geo.routing.driver')] ?? NullRoutingDriver::class;

            return $app->make($class);
        });

        $this->app->bind(GeocodingDriver::class, function ($app) {
            $class = self::GEOCODING_DRIVERS[(string) config('ehail-geo.geocoding.driver')] ?? NullGeocoder::class;

            return $app->make($class);
        });

        // Dispatch rebinds this to its availability service once it lands.
        $this->app->bindIf(DriverPresence::class, ProfileDriverPresence::class);
        // Safety rebinds this so rides under an open incident keep their raw trail.
        $this->app->bindIf(TrailRetentionHold::class, NoTrailRetentionHold::class);
    }

    public function boot(): void
    {
        RateLimiter::for('ehail-geo-search', fn (Request $request) => Limit::perMinute(40)->by('geo-search:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-geo-route', fn (Request $request) => Limit::perMinute(20)->by('geo-route:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-geo-read', fn (Request $request) => Limit::perMinute(90)->by('geo-read:'.($request->user()?->id ?: $request->ip())));
        // One location post per driver per 2 seconds (devices post every 4 s; a queue after a dead zone goes in one post).
        RateLimiter::for('ehail-geo-location', fn (Request $request) => (new Limit('geo-location:'.($request->user()?->id ?: $request->ip()), 1, 2)));

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeGeoData::class, SeedReferenceAreas::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('ehail:geo-purge')->dailyAt('02:40')->withoutOverlapping();
        });
    }
}
