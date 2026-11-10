<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Data\LockedQuote;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Geo\Routing\RideRoutePlanner;
use App\Domain\Geo\Routing\RouteResult;
use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Exceptions\QuoteRejected;
use App\Domain\Pricing\Exceptions\RouteUnavailable;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\PromotionRedemption;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Promotions\PromotionRedeemer;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\PricedOption;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch's FareQuoteGateway over the pricing domain (R46_INTEGRATION.md section 1).
 *
 * findForPassenger() reads the stored, signed FareQuote (pricing's QuoteBinder re-checks the signature,
 * expiry, route and promotion inside the ride-creation transaction); bind() is that binding, plus attaching
 * the quoted road route to the ride (geo RideRoutePlanner) so deviation is measured against the route the
 * passenger agreed to. requote() asks the FareQuoter for a fresh quote for a scheduled ride.
 */
class PricingFareQuoteGateway implements FareQuoteGateway
{
    public function __construct(
        private readonly QuoteBinder $binder,
        private readonly FareQuoter $quoter,
        private readonly PricingRoutePlanner $routes,
        private readonly RideRoutePlanner $rideRoutes,
        private readonly PromotionRedeemer $promotions,
        private readonly EligibleSupplyCounter $supply,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function findForPassenger(string $quoteId, User $passenger): ?LockedQuote
    {
        $quote = FareQuote::query()->with('category')->whereKey($quoteId)->first();

        if ($quote === null || $quote->passenger_id !== $passenger->id || $quote->purpose !== 'booking' || $quote->isExpired()) {
            return null;
        }

        return $this->locked($quote);
    }

    public function bind(LockedQuote $quote, User $passenger, Ride $ride): void
    {
        $this->assertSupplyForRideNow($quote, $ride);
        $this->releasePreviousSnapshot($ride);

        try {
            $this->binder->bind(
                $quote->id,
                $passenger,
                GeoPoint::from($quote->pickupLat, $quote->pickupLng),
                GeoPoint::from($quote->dropoffLat, $quote->dropoffLng),
                $ride,
                $quote->category,
            );
        } catch (QuoteRejected $e) {
            throw new DispatchRefused('quote_'.$e->reason, $e->getMessage(), $e->reason === 'already_used' ? 409 : 422);
        }

        $this->attachQuotedRoute($quote, $ride);
    }

    /**
     * Quotes price without current supply (D-1902); a ride for NOW is refused when the counter saw no eligible
     * driver near the pickup this moment (inside the ride-creation transaction, so nothing is kept). Scheduled
     * rides are not gated: they are matched closer to their time. An unknown count (null) does not block.
     */
    private function assertSupplyForRideNow(LockedQuote $quote, Ride $ride): void
    {
        if ($ride->scheduled_for !== null) {
            return;
        }

        $category = RideCategory::query()->where('key', $quote->category)->first();

        if ($category !== null && $this->supply->count($category, GeoPoint::from($quote->pickupLat, $quote->pickupLng), null, now()) === 0) {
            throw new DispatchRefused('no_supply', 'No '.$category->name.' drivers are nearby right now, so this ride cannot be requested yet. The price shown stays the same: try again in a few minutes, choose another ride type, or schedule the ride.', 409);
        }
    }

    /**
     * A scheduled ride whose passenger accepted a new fare is bound again: the unfinalised snapshot of the old
     * quote is removed (its promotion given back) so the new quote's snapshot replaces it. A finalised fare is
     * never replaced.
     */
    private function releasePreviousSnapshot(Ride $ride): void
    {
        $previous = RideFare::query()->where('ride_id', $ride->id)->lockForUpdate()->first();

        if ($previous === null) {
            return;
        }

        if ($previous->isFinalised()) {
            throw new DispatchRefused('fare_already_final', 'This ride\'s fare is already final.', 409);
        }

        if ($previous->promotion_redemption_id !== null && ($redemption = PromotionRedemption::query()->find($previous->promotion_redemption_id)) !== null) {
            $this->promotions->reverse($redemption);
        }

        $previous->delete();
    }

    public function requote(Ride $ride): ?LockedQuote
    {
        $passenger = User::find($ride->passenger_id);

        if ($passenger === null || $ride->pickup_lat === null || $ride->dropoff_lat === null) {
            return null;
        }

        $option = $this->quoter->quote(new QuoteRequest(
            GeoPoint::from($ride->pickup_lat, $ride->pickup_lng),
            GeoPoint::from($ride->dropoff_lat, $ride->dropoff_lng),
            $ride->category_code ?? $ride->vehicle_type,
            $passenger,
        ));

        if (! $option instanceof PricedOption || $option->quote === null) {
            return null;
        }

        $quote = $option->quote->setRelation('category', $option->category);

        return $this->locked($quote, (string) $ride->pickup_address, (string) $ride->dropoff_address);
    }

    private function locked(FareQuote $quote, ?string $pickupAddress = null, ?string $dropoffAddress = null): LockedQuote
    {
        return new LockedQuote(
            id: $quote->id,
            passengerId: (int) $quote->passenger_id,
            category: (string) $quote->category?->key,
            totalMinor: $quote->total_minor,
            currency: $quote->currency,
            expiresAt: Carbon::instance($quote->expires_at),
            pickupLat: (float) $quote->pickup_lat,
            pickupLng: (float) $quote->pickup_lng,
            pickupAddress: $pickupAddress ?? self::pinnedLabel((float) $quote->pickup_lat, (float) $quote->pickup_lng),
            dropoffLat: (float) $quote->dropoff_lat,
            dropoffLng: (float) $quote->dropoff_lng,
            dropoffAddress: $dropoffAddress ?? self::pinnedLabel((float) $quote->dropoff_lat, (float) $quote->dropoff_lng),
            routeDistanceM: $quote->route_distance_m,
            routeDurationS: $quote->route_duration_s,
            components: $quote->components,
            ruleSetId: (string) $quote->rule_set_id,
            ruleVersion: (string) $quote->rule_version,
        );
    }

    /**
     * What the ride shows when the passenger app sent no address label: the pinned point, never an invented address.
     */
    public static function pinnedLabel(float $lat, float $lng): string
    {
        return sprintf('Pinned location (%.5f, %.5f)', $lat, $lng);
    }

    /**
     * The route cache keeps a quoted route for longer than a quote lives, so asking the planner again returns the
     * same road route; it is attached only when its polyline hash matches the one the quote was signed with.
     * Otherwise nothing is attached and geo plans the ride's route on first need (RideRoutePlanner::planFor).
     */
    private function attachQuotedRoute(LockedQuote $locked, Ride $ride): void
    {
        $quote = FareQuote::query()->find($locked->id);

        if ($quote === null) {
            return;
        }

        try {
            $route = $this->routes->route(GeoPoint::from($locked->pickupLat, $locked->pickupLng), GeoPoint::from($locked->dropoffLat, $locked->dropoffLng));
        } catch (RouteUnavailable) {
            return;
        }

        if (! hash_equals($quote->polyline_hash, $route->polylineHash())) {
            Log::info('ehail.integration.quoted_route_not_attached', ['ride_id' => $ride->id, 'quote_id' => $quote->id]);

            return;
        }

        $this->rideRoutes->attach($ride, new RouteResult($route->distanceM, $route->durationS, $route->polyline, $route->provider));
    }
}
