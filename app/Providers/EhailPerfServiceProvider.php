<?php

namespace App\Providers;

use App\Domain\Perf\LazyTouchDatabaseSessionHandler;
use App\Domain\Perf\LeanPollResponses;
use App\Domain\Perf\QueryProbe;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 performance and health (docs/R46_PERF.md): the lazy-touch database session handler, the driver sync
 * limiter, health commands and the development-only cost probe.
 */
class EhailPerfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('ehail_perf.php'), 'ehail_perf');
    }

    public function boot(): void
    {
        // Replaces the "database" session driver with the same table and lifetime, minus the redundant writes.
        Session::extend('database', function ($app) {
            $config = $app['config'];

            return new LazyTouchDatabaseSessionHandler(
                $app['db']->connection($config->get('session.connection')),
                $config->get('session.table'),
                $config->get('session.lifetime'),
                $app,
                (int) $config->get('ehail_perf.session_touch_seconds', 60),
            );
        });

        // One request carries the driver's positions and asks for the feed (polls every 2-6 s plus retries).
        RateLimiter::for('ehail-driver-sync', fn (Request $request) => Limit::perMinute(90)->by('sync:'.($request->user()?->id ?: $request->ip())));

        // First in the global stack, so it sees the response after every other middleware added its headers.
        $this->app->make(Kernel::class)->prependMiddleware(LeanPollResponses::class);

        if (config('ehail_perf.probe') && $this->app->environment('local', 'testing')) {
            $this->app->make(Kernel::class)->prependMiddleware(QueryProbe::class);
        }
    }
}
