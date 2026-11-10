<?php

namespace App\Providers;

use App\Domain\Payments\Console\DraftPayoutBatch;
use App\Domain\Payments\Console\EvaluateIncentives;
use App\Domain\Payments\Console\MoneySweep;
use App\Domain\Payments\Console\ReconcileLedger;
use App\Domain\Payments\Console\SettlementSweep;
use App\Domain\Payments\Http\EnsureNoPaymentDue;
use App\Domain\Payments\Listeners\MoneyRideListener;
use App\Domain\Payments\Policies\MoneyPolicies;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Payments\Seams\ConfigCommissionSplitter;
use App\Domain\Payments\Seams\ConfigPayeeResolver;
use App\Domain\Payments\Seams\DriverActivitySource;
use App\Domain\Payments\Seams\EarningsSplitSource;
use App\Domain\Payments\Seams\FareSource;
use App\Domain\Payments\Seams\LegacyFareSource;
use App\Domain\Payments\Seams\NoCancellationFee;
use App\Domain\Payments\Seams\NoSupportCases;
use App\Domain\Payments\Seams\PayeeResolver;
use App\Domain\Payments\Seams\SupportCaseOpener;
use App\Domain\Payments\Seams\UnconfiguredBusinessBilling;
use App\Domain\Payments\Seams\UnmeasuredDriverActivity;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\TripCompleted;
use App\Models\Ride;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 money domain: payments, ledger, earnings, payouts, incentives, receipts.
 *
 * Seams to other domains are bound with honest defaults (bindIf, so the owning domain's provider or
 * the merge can bind the real implementation): fare from rides.final_fare, one configured commission
 * rate, no cancellation fee, no business billing, no online-time/acceptance/referral data, no support
 * case queue. docs/R46_MONEY.md lists each and what replaces it.
 */
class EhailMoneyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(FareSource::class, LegacyFareSource::class);
        $this->app->bindIf(EarningsSplitSource::class, ConfigCommissionSplitter::class);
        $this->app->bindIf(CancellationFeeSource::class, NoCancellationFee::class);
        $this->app->bindIf(BusinessBilling::class, UnconfiguredBusinessBilling::class);
        $this->app->bindIf(PayeeResolver::class, ConfigPayeeResolver::class);
        $this->app->bindIf(DriverActivitySource::class, UnmeasuredDriverActivity::class);
        $this->app->bindIf(SupportCaseOpener::class, NoSupportCases::class);
    }

    public function boot(Router $router): void
    {
        Event::listen(TripCompleted::class, [MoneyRideListener::class, 'tripCompleted']);
        Event::listen(RideCancelled::class, [MoneyRideListener::class, 'rideCancelled']);
        Ride::updated(fn (Ride $ride) => app(MoneyRideListener::class)->rideUpdated($ride));

        $policies = MoneyPolicies::class;
        Gate::define('money.view-receipt', [$policies, 'viewReceipt']);
        Gate::define('money.view-earning', [$policies, 'viewEarning']);
        Gate::define('money.view-payout', [$policies, 'viewPayout']);
        Gate::define('money.view-fleet-earnings', [$policies, 'viewFleetEarnings']);

        RateLimiter::for('money-card-setup', fn (Request $request) => Limit::perHour(10)->by('card-setup:'.$request->user()?->id));
        RateLimiter::for('money-pay', fn (Request $request) => Limit::perMinute(6)->by('money-pay:'.$request->user()?->id));
        RateLimiter::for('money-cash', fn (Request $request) => Limit::perMinute(20)->by('money-cash:'.$request->user()?->id));

        // For dispatch's ride-request routes: refuses a new request while the passenger owes money.
        $router->aliasMiddleware('ehail.payment-clear', EnsureNoPaymentDue::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MoneySweep::class, EvaluateIncentives::class, DraftPayoutBatch::class, ReconcileLedger::class, SettlementSweep::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('money:sweep')->everyMinute()->withoutOverlapping();
            $schedule->command('ehail:settlement-sweep')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('money:evaluate-incentives')->hourly()->withoutOverlapping();
            $schedule->command('money:draft-payouts')->dailyAt((string) config('money.payouts.schedule_time', '06:00'))->withoutOverlapping();
            $schedule->command('money:reconcile')->dailyAt('03:30')->withoutOverlapping();
        });
    }
}
