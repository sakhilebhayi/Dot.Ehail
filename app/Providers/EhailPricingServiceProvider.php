<?php

namespace App\Providers;

use App\Domain\Pricing\Adapters\GeoRoutePlannerBridge;
use App\Domain\Pricing\Adapters\GeoZoneLookupBridge;
use App\Domain\Pricing\Adapters\NoDemandSignal;
use App\Domain\Pricing\Adapters\NoTollEstimator;
use App\Domain\Pricing\Adapters\NoZoneLookup;
use App\Domain\Pricing\Adapters\UnconfiguredRoutePlanner;
use App\Domain\Pricing\Adapters\UnknownSupplyCounter;
use App\Domain\Pricing\Console\InstallExampleTariffCommand;
use App\Domain\Pricing\Console\PruneFareQuotesCommand;
use App\Domain\Pricing\Contracts\DemandSignal;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Contracts\TollEstimator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 pricing and catalogue wiring (docs/R46_PRICING.md). Seams to other domains are bound here:
 *  - routing and zones go through the geo domain when its classes exist, else to honest "not configured" adapters;
 *  - supply, demand and tolls default to "unknown / none" with bindIf, so the owning domain can bind a real one.
 * Rate limiters live here (never in route files: route:cache).
 */
class EhailPricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PricingRoutePlanner::class, function ($app) {
            return GeoRoutePlannerBridge::available()
                ? new GeoRoutePlannerBridge($app->make(GeoRoutePlannerBridge::PLANNER))
                : new UnconfiguredRoutePlanner;
        });

        $this->app->bind(PricingZoneLookup::class, function ($app) {
            return GeoZoneLookupBridge::available()
                ? new GeoZoneLookupBridge($app->make(GeoZoneLookupBridge::RESOLVER))
                : new NoZoneLookup;
        });

        $this->app->bindIf(EligibleSupplyCounter::class, UnknownSupplyCounter::class);
        $this->app->bindIf(DemandSignal::class, NoDemandSignal::class);
        $this->app->bindIf(TollEstimator::class, NoTollEstimator::class);
    }

    public function boot(): void
    {
        RateLimiter::for('ehail-fare-estimate', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('ehail_pricing.rate_limits.public_estimate_per_minute', 20)))->by('estimate|'.$request->ip());
        });

        RateLimiter::for('ehail-fare-quote', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('ehail_pricing.rate_limits.quote_per_minute', 30)))->by('quote|'.($request->user()?->id ?? $request->ip()));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([InstallExampleTariffCommand::class, PruneFareQuotesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ehail:pricing:prune-quotes')->hourly()->withoutOverlapping();
        });
    }
}
