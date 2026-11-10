<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 driver app (/drive, driver agent). Limiters live here, never in the route file (route:cache).
 */
class EhailDriverServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The driver app's own small JSON endpoints (offer earnings breakdown, arrival check, trip summary).
        RateLimiter::for('ehail-driver-api', fn (Request $request) => Limit::perMinute(60)->by('drive-api:'.($request->user()?->id ?: $request->ip())));
        // Forms on the driver pages (bank account, verification upload wrapper).
        RateLimiter::for('ehail-driver-forms', fn (Request $request) => Limit::perMinute(12)->by('drive-forms:'.($request->user()?->id ?: $request->ip())));
    }
}
