<?php

namespace App\Providers;

use App\Domain\Dispatch\Contracts\CancellationFees;
use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Domain\Dispatch\Contracts\DriverEarningsEstimator;
use App\Domain\Dispatch\Contracts\DriverEligibility;
use App\Domain\Dispatch\Contracts\DriverLocationSink;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Contracts\ServiceAreaCheck;
use App\Domain\Dispatch\Contracts\TripFareFinaliser;
use App\Domain\Dispatch\Seams\AgreedFareFinaliser;
use App\Domain\Dispatch\Seams\AvailabilityDriverLocator;
use App\Domain\Dispatch\Seams\CashOnlyPaymentMethods;
use App\Domain\Dispatch\Seams\ConfigTierEligibility;
use App\Domain\Dispatch\Seams\NoCancellationFees;
use App\Domain\Dispatch\Seams\NoEarningsEstimate;
use App\Domain\Dispatch\Seams\NullLocationSink;
use App\Domain\Dispatch\Seams\ProfileApprovalEligibility;
use App\Domain\Dispatch\Seams\StraightLineEta;
use App\Domain\Dispatch\Seams\UnavailableFareQuotes;
use App\Domain\Dispatch\Seams\UnknownServiceArea;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 dispatch domain (docs/R46_DISPATCH.md): seam bindings and rate limiters.
 *
 * Every seam is bound with bindIf, so the owning domain's provider (pricing,
 * geo, safety, money) replaces the default simply by binding the contract:
 *   FareQuoteGateway, TripFareFinaliser, CancellationFees, DriverEarningsEstimator,
 *   CategoryEligibility                      -> pricing / catalog
 *   DriverLocator, PickupEtaEstimator, ServiceAreaCheck, DriverLocationSink -> geo
 *   DriverEligibility                        -> safety (VerificationService)
 *   PaymentMethodResolver                    -> money
 * The defaults are honest: they apply today's rules or report "not available".
 */
class EhailDispatchServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $seams = [
        FareQuoteGateway::class => UnavailableFareQuotes::class,
        TripFareFinaliser::class => AgreedFareFinaliser::class,
        CancellationFees::class => NoCancellationFees::class,
        DriverEarningsEstimator::class => NoEarningsEstimate::class,
        CategoryEligibility::class => ConfigTierEligibility::class,
        DriverLocator::class => AvailabilityDriverLocator::class,
        PickupEtaEstimator::class => StraightLineEta::class,
        ServiceAreaCheck::class => UnknownServiceArea::class,
        DriverLocationSink::class => NullLocationSink::class,
        DriverEligibility::class => ProfileApprovalEligibility::class,
        PaymentMethodResolver::class => CashOnlyPaymentMethods::class,
    ];

    public function register(): void
    {
        foreach ($this->seams as $contract => $default) {
            $this->app->bindIf($contract, $default);
        }
    }

    public function boot(): void
    {
        // Polling: passenger every 3 s, driver every 2 s, plus headroom for retries and a second tab.
        RateLimiter::for('ehail-feed', fn (Request $request) => Limit::perMinute(90)->by('feed:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-heartbeat', fn (Request $request) => Limit::perMinute(60)->by('hb:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-ride-request', fn (Request $request) => Limit::perMinute(10)->by('req:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ehail-ride-action', fn (Request $request) => Limit::perMinute(60)->by('act:'.($request->user()?->id ?: $request->ip())));
    }
}
