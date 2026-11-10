<?php

namespace App\Providers;

use App\Domain\Health\Console\HealthCommand;
use App\Domain\Health\HealthReport;
use App\Domain\Perf\DatabaseOutage;
use App\Domain\Perf\LazyTouchDatabaseSessionHandler;
use App\Domain\Perf\LeanPollResponses;
use App\Domain\Perf\QueryProbe;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

        // A lost or refused database connection is a temporary outage (503 + Retry-After), not a 500 (D-1510).
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(fn (\Throwable $e, Request $request) => DatabaseOutage::render($e, $request));
        }

        // First in the global stack, so it sees the response after every other middleware added its headers.
        $this->app->make(Kernel::class)->prependMiddleware(LeanPollResponses::class);

        // Health (docs/R46_PERF.md section 6): the cron's own heartbeat, so a stopped cron is visible.
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            $key = match ($event->command) {
                'schedule:run' => HealthReport::SCHEDULE_KEY,
                'ehail:dispatch-tick' => HealthReport::TICK_KEY,
                default => null,
            };

            if ($key !== null && $event->exitCode === 0) {
                rescue(fn () => Cache::forever($key, now()->getTimestamp()), report: false);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('ehail:health --alert')->everyFiveMinutes()->withoutOverlapping();
        });

        if (config('ehail_perf.probe') && $this->app->environment('local', 'testing')) {
            $this->app->make(Kernel::class)->prependMiddleware(QueryProbe::class);
        }
    }
}
