<?php

namespace App\Providers;

use App\Domain\Business\BusinessRefusal;
use App\Domain\Business\Console\BusinessSweep;
use App\Domain\Business\Console\IssueStatements;
use App\Domain\Business\Listeners\ReleaseOnRideEnd;
use App\Domain\Business\Seams\AccountBusinessBilling;
use App\Domain\Business\Seams\BusinessChoiceGuard;
use App\Domain\Business\Seams\BusinessPaymentMethodResolver;
use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideExpired;
use App\Domain\Rides\Events\RideFailed;
use App\Domain\Rides\Events\RideNoShow;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 business accounts (docs/R46_BUSINESS.md).
 *
 *  - Answers money's BusinessBilling seam (bound here; the integration provider leaves it to this domain).
 *  - Wraps whatever PaymentMethodResolver is bound (Container::extend, so it also wraps the integration's
 *    binding registered later) to enforce business rules inside the ride-request transaction.
 *  - Guards money's per-ride payment choice: only rides booked on the account can be billed to it.
 *  - Releases reservations when rides end; schedules statements, dunning and the sweep.
 */
class EhailBusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->bind(BusinessBilling::class, AccountBusinessBilling::class);

        $this->app->extend(PaymentMethodResolver::class, fn (PaymentMethodResolver $inner, $app) => $inner instanceof BusinessPaymentMethodResolver
            ? $inner
            : $app->make(BusinessPaymentMethodResolver::class, ['inner' => $inner]));
    }

    public function boot(): void
    {
        // Business refusals: JSON {error, message, reasons} for API callers, a toast and the form back for pages.
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (BusinessRefusal $e, Request $request) {
                if ($request->expectsJson() || $request->is('business/profiles', 'business/trips/prepare') || ($request->is('business/approvals') && $request->isMethod('post'))) {
                    return response()->json(['error' => 'business_'.$e->reasonCode, 'message' => $e->getMessage(), 'reasons' => $e->reasons], $e->status);
                }

                return back()->withInput()->with('error', $e->getMessage())->withErrors(['business' => $e->getMessage()]);
            });
        }

        RidePaymentChoice::saving(fn (RidePaymentChoice $choice) => app(BusinessChoiceGuard::class)->saving($choice));

        Event::listen([RideCancelled::class, RideExpired::class, RideFailed::class, RideNoShow::class], [ReleaseOnRideEnd::class, 'handle']);

        RateLimiter::for('ehail-business-api', function (Request $request) {
            return Limit::perMinute(60)->by('business|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([IssueStatements::class, BusinessSweep::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('business:sweep')->everyMinute()->withoutOverlapping();
            $schedule->command('business:statements')->dailyAt('05:15')->withoutOverlapping();
        });
    }
}
