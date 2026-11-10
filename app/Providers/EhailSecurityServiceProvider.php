<?php

namespace App\Providers;

use App\Domain\Security\KnownDevices;
use App\Http\Middleware\RequireRecentSignIn;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 security and privacy wiring (docs/R46_SECURITY.md): the recent-sign-in middleware alias, sign-in
 * bookkeeping (recent-auth timestamp, new-device notices), and the limiters this pass added. Limiters and
 * aliases live here, never in route files (route:cache).
 */
class EhailSecurityServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('ehail.recent-auth', RequireRecentSignIn::class);

        Event::listen(Login::class, function (Login $event) {
            $request = request();

            if ($request->hasSession()) {
                $request->session()->put(RequireRecentSignIn::SIGNED_IN_AT, time());
            }

            if ($event->user instanceof User) {
                $this->app->make(KnownDevices::class)->signedIn($event->user, $request);
            }
        });

        // Card verification (the R1 setup charge) on top of money's per-user hourly limit: per user per day and per
        // IP per hour, so stolen-card testing cannot spread across accounts or run all day (S-26).
        RateLimiter::for('ehail-card-setup-abuse', fn (Request $request) => [
            Limit::perDay((int) config('ehail_security.card_setup_per_user_daily', 20))->by('card-setup-day:'.$request->user()?->id),
            Limit::perHour((int) config('ehail_security.card_setup_per_ip_hourly', 20))->by('card-setup-ip:'.$request->ip()),
        ]);

        // Personal-data export: a heavy read, a few times an hour.
        RateLimiter::for('ehail-data-export', fn (Request $request) => Limit::perHour(5)->by('data-export:'.$request->user()?->id));
    }
}
