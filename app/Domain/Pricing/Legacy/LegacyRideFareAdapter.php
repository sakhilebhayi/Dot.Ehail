<?php

namespace App\Domain\Pricing\Legacy;

use App\Domain\Catalog\RideCategoryCatalogue;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\MoneyFormatter;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\PricedOption;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\Ride;
use App\Models\User;

/**
 * Bridges the pre-R-46 request form (address + optional coordinates + vehicle_type) to the new engine, replacing the
 * old straight-line FareCalculator::estimate. The old vehicle_type maps to its continuing category (suv -> xl).
 * With no coordinates, no road route or no tariff there is no estimate (null), exactly as the form already explains.
 * Existing rides are never re-priced: their decimal columns stay as recorded.
 */
final class LegacyRideFareAdapter
{
    public function __construct(private FareQuoter $quoter, private QuoteBinder $binder) {}

    public function estimate(User $passenger, mixed $pickupLat, mixed $pickupLng, mixed $dropoffLat, mixed $dropoffLng, string $vehicleType): LegacyEstimate
    {
        if ($pickupLat === null || $pickupLng === null || $dropoffLat === null || $dropoffLng === null) {
            return LegacyEstimate::none('no_coordinates');
        }

        $category = RideCategoryCatalogue::forLegacyVehicleType($vehicleType);

        if ($category === null) {
            return LegacyEstimate::none('category_unknown');
        }

        $result = $this->quoter->quote(new QuoteRequest(
            GeoPoint::from($pickupLat, $pickupLng),
            GeoPoint::from($dropoffLat, $dropoffLng),
            $category->key,
            $passenger,
            null,
            null,
            'legacy_request',
        ));

        if (! $result instanceof PricedOption || $result->quote === null) {
            return LegacyEstimate::none($result->reason ?? 'unavailable');
        }

        return new LegacyEstimate(
            $result->quote,
            MoneyFormatter::decimal(IntegerMath::divide($result->route->distanceM, 10), 2),
            $result->breakdown->total()->toDecimalString(),
            null,
        );
    }

    /**
     * Bind the quote to the ride just created (call inside the same transaction).
     */
    public function bind(FareQuote $quote, Ride $ride, User $passenger): RideFare
    {
        return $this->binder->bind($quote->id, $passenger, GeoPoint::from($quote->pickup_lat, $quote->pickup_lng), GeoPoint::from($quote->dropoff_lat, $quote->dropoff_lng), $ride);
    }
}
