<?php

namespace App\Providers;

use App\Domain\Comms\ChannelStatus;
use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\Console\DeliverCommand;
use App\Domain\Comms\Console\PruneCommand;
use App\Domain\Comms\Console\VapidKeysCommand;
use App\Domain\Comms\Listeners\ApproachWatcher;
use App\Domain\Comms\Listeners\ModelObservers;
use App\Domain\Comms\Listeners\RideEventsListener;
use App\Domain\Comms\Listeners\SourceNotificationListener;
use App\Domain\Comms\Preferences;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\Models\Receipt;
use App\Models\Ride;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 comms (docs/R46_COMMS.md): routes domain events and every in-app notification to the
 * person's chosen channels. Listeners, model hooks, limiters and schedules live here, never in
 * route files. Model hooks on other domains' models are registered from here so their files stay
 * untouched; each runs only after the surrounding transaction commits.
 */
class EhailCommsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommsCategories::class);
        $this->app->singleton(VapidKeys::class);
        $this->app->singleton(Preferences::class);
        $this->app->singleton(ChannelStatus::class);
    }

    public function boot(): void
    {
        // <x-comms::shell>, <x-comms::bell> and friends (resources/views/comms/components).
        Blade::anonymousComponentPath(resource_path('views/comms/components'), 'comms');

        $this->limiters();
        $this->listeners();
        $this->modelHooks();

        if ($this->app->runningInConsole()) {
            $this->commands([DeliverCommand::class, PruneCommand::class, VapidKeysCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ehail:comms-deliver')->everyMinute()->withoutOverlapping();
            $schedule->command('ehail:comms-prune')->dailyAt('03:30')->withoutOverlapping();
        });
    }

    private function limiters(): void
    {
        $key = fn (Request $request) => (string) ($request->user()?->id ?? $request->ip());

        RateLimiter::for('ehail-comms-read', fn (Request $request) => Limit::perMinute(120)->by($key($request)));
        RateLimiter::for('ehail-comms-write', fn (Request $request) => Limit::perMinute(30)->by($key($request)));
        RateLimiter::for('ehail-comms-sms', fn (Request $request) => [Limit::perHour(5)->by($key($request)), Limit::perDay(10)->by($key($request))]);
    }

    private function listeners(): void
    {
        Event::subscribe(RideEventsListener::class);

        Event::listen(NotificationSending::class, [SourceNotificationListener::class, 'sending']);
        Event::listen(NotificationSent::class, [SourceNotificationListener::class, 'sent']);
        Event::listen(NotificationFailed::class, [SourceNotificationListener::class, 'failed']);
    }

    private function modelHooks(): void
    {
        $after = fn (callable $callback) => function ($model) use ($callback) {
            DB::afterCommit(fn () => $callback($model));
        };

        Ride::created($after(fn (Ride $ride) => app(ModelObservers::class)->rideCreated($ride)));
        RideOffer::created($after(fn (RideOffer $offer) => app(ModelObservers::class)->offerCreated($offer)));
        Receipt::created($after(fn (Receipt $receipt) => app(ModelObservers::class)->receiptCreated($receipt)));
        Payout::updated($after(fn (Payout $payout) => app(ModelObservers::class)->payoutUpdated($payout)));
        IncentiveProgress::saved($after(fn (IncentiveProgress $progress) => app(ModelObservers::class)->incentiveSaved($progress)));
        DriverAvailability::updated(fn (DriverAvailability $row) => app(ApproachWatcher::class)->availabilityUpdated($row));
    }
}
