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
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Integration\Dispatch\CatalogCategoryEligibility;
use App\Domain\Integration\Dispatch\GeoDriverLocator;
use App\Domain\Integration\Dispatch\GeoLocationSink;
use App\Domain\Integration\Dispatch\GeoPickupEta;
use App\Domain\Integration\Dispatch\GeoServiceAreaCheck;
use App\Domain\Integration\Dispatch\MoneyPaymentMethodResolver;
use App\Domain\Integration\Dispatch\PricingCancellationFees;
use App\Domain\Integration\Dispatch\PricingEarningsEstimator;
use App\Domain\Integration\Dispatch\PricingFareQuoteGateway;
use App\Domain\Integration\Dispatch\PricingTripFareFinaliser;
use App\Domain\Integration\Dispatch\VerificationDriverEligibilityAdapter;
use App\Domain\Integration\Geo\AvailabilityDriverPresence;
use App\Domain\Integration\Geo\MirroringLocationStore;
use App\Domain\Integration\Geo\OpenIncidentTrailHold;
use App\Domain\Integration\Listeners\SettleFareOnRideEnd;
use App\Domain\Integration\Listeners\TakeDriverOffline;
use App\Domain\Integration\Money\DispatchDriverActivity;
use App\Domain\Integration\Money\PricingCancellationFeeSource;
use App\Domain\Integration\Money\PricingEarningsSplitSource;
use App\Domain\Integration\Money\PricingFareSource;
use App\Domain\Integration\Money\SafetySupportCaseOpener;
use App\Domain\Integration\Pricing\DispatchSupplyCounter;
use App\Domain\Integration\Safety\DispatchRidePin;
use App\Domain\Integration\Safety\DispatchTripProgress;
use App\Domain\Integration\Safety\GeoSafetyLocationSource;
use App\Domain\Integration\Safety\PricingFareReviewRequest;
use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Payments\Seams\DriverActivitySource;
use App\Domain\Payments\Seams\EarningsSplitSource;
use App\Domain\Payments\Seams\FareSource;
use App\Domain\Payments\Seams\SupportCaseOpener;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideExpired;
use App\Domain\Rides\Events\RideFailed;
use App\Domain\Rides\Events\RideNoShow;
use App\Domain\Safety\Seams\RidePinSource;
use App\Domain\Safety\Seams\SafetyLocationSource;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Domain\Support\Seams\FareAdjustmentRequest;
use App\Domain\Verification\Events\DriverForcedOffline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 integration (docs/R46_INTEGRATION.md): binds every cross-domain seam to the real implementation of the
 * domain that owns it. Registered LAST in bootstrap/providers.php, and it binds unconditionally, so it wins
 * over the owners' bindIf defaults (which stay as the honest fallbacks each domain is tested with).
 *
 * Seams deliberately left at their default (and why) are listed in R46_INTEGRATION.md section 1:
 * money's BusinessBilling (business wave) and PayeeResolver (already reads fleet membership), pricing's
 * DemandSignal and TollEstimator (no signal or toll source exists).
 */
class EhailIntegrationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> seam => implementation */
    public array $bindings = [
        // Dispatch consumes pricing, catalog, geo, safety and money.
        FareQuoteGateway::class => PricingFareQuoteGateway::class,
        TripFareFinaliser::class => PricingTripFareFinaliser::class,
        CancellationFees::class => PricingCancellationFees::class,
        DriverEarningsEstimator::class => PricingEarningsEstimator::class,
        CategoryEligibility::class => CatalogCategoryEligibility::class,
        DriverLocator::class => GeoDriverLocator::class,
        PickupEtaEstimator::class => GeoPickupEta::class,
        ServiceAreaCheck::class => GeoServiceAreaCheck::class,
        DriverLocationSink::class => GeoLocationSink::class,
        DriverEligibility::class => VerificationDriverEligibilityAdapter::class,
        PaymentMethodResolver::class => MoneyPaymentMethodResolver::class,
        // Geo consumes dispatch and safety; its store also feeds dispatch's availability index.
        LocationStore::class => MirroringLocationStore::class,
        DriverPresence::class => AvailabilityDriverPresence::class,
        TrailRetentionHold::class => OpenIncidentTrailHold::class,
        // Safety consumes geo, dispatch and pricing.
        SafetyLocationSource::class => GeoSafetyLocationSource::class,
        TripProgressSource::class => DispatchTripProgress::class,
        RidePinSource::class => DispatchRidePin::class,
        FareAdjustmentRequest::class => PricingFareReviewRequest::class,
        // Money consumes pricing, dispatch and safety.
        FareSource::class => PricingFareSource::class,
        EarningsSplitSource::class => PricingEarningsSplitSource::class,
        CancellationFeeSource::class => PricingCancellationFeeSource::class,
        DriverActivitySource::class => DispatchDriverActivity::class,
        SupportCaseOpener::class => SafetySupportCaseOpener::class,
        // Pricing consumes dispatch availability and geo positions.
        EligibleSupplyCounter::class => DispatchSupplyCounter::class,
    ];

    public function boot(): void
    {
        // The ride lifecycle closes the pricing record (and charges a no-show) after each transition commits.
        Event::listen([RideCancelled::class, RideExpired::class, RideFailed::class, RideNoShow::class], [SettleFareOnRideEnd::class, 'handle']);

        // Verification taking a driver offline takes them out of dispatch too.
        Event::listen(DriverForcedOffline::class, [TakeDriverOffline::class, 'handle']);
    }
}
