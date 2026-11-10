<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 passenger app (wave 2): limiters for the passenger app's own small JSON endpoints, and the money domain's
 * card-setup return address pointed at the passenger account page when the operator left it at its default (D-807).
 * Limiters live here, never in route files (route:cache).
 */
class EhailPassengerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('ehail-passenger', fn (Request $request) => Limit::perMinute(60)->by('pax:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-passenger-eta', fn (Request $request) => Limit::perMinute(20)->by('pax-eta:'.($request->user()?->id ?: $request->ip())));

        // Cards are managed from /ride/account now; money's default sends people back to the old dashboard.
        if (config('money.card_setup.return_to') === '/dashboard') {
            config(['money.card_setup.return_to' => '/ride/account']);
        }
    }
}
