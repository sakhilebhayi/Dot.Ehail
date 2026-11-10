<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Exceptions\PromotionUnavailable;
use App\Domain\Pricing\Exceptions\QuoteRejected;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Promotions\PromotionRedeemer;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Locks a quote to the ride being created. DISPATCH CONTRACT: call bind() inside the same transaction that creates
 * the ride; a QuoteRejected exception must roll the ride back and send the passenger back to re-quote.
 *
 * Checks, in order: the quote exists and belongs to this passenger (a foreign quote looks exactly like a missing
 * one), the signature still matches every stored field, it was not used, it has not expired, the booking's pickup
 * and drop-off are within the configured tolerance of the quoted points, and the category matches. The amount is
 * never taken from the client: there is no amount parameter. Then the promotion (if any) is redeemed, the quote is
 * consumed, and the ride's fare snapshot (ride_fares) is written.
 */
final class QuoteBinder
{
    public function __construct(private QuoteSigner $signer, private PromotionRedeemer $promotions) {}

    public function bind(string $quoteId, User $passenger, GeoPoint $pickup, GeoPoint $dropoff, Ride $ride, ?string $categoryKey = null): RideFare
    {
        return DB::transaction(function () use ($quoteId, $passenger, $pickup, $dropoff, $ride, $categoryKey) {
            $quote = FareQuote::query()->whereKey($quoteId)->lockForUpdate()->first();

            if ($quote === null || $quote->passenger_id !== $passenger->id) {
                throw new QuoteRejected('not_found');
            }

            if (! $this->signer->verify($quote)) {
                // Logged, not audited: an audit row written here would roll back with the refused booking.
                Log::warning('ehail.pricing.quote_tamper_detected', ['quote_id' => $quote->id, 'passenger_id' => $passenger->id]);

                throw new QuoteRejected('tampered');
            }

            if ($quote->consumed_at !== null) {
                throw new QuoteRejected('already_used');
            }

            if ($quote->isExpired()) {
                throw new QuoteRejected('expired');
            }

            $tolerance = (float) config('ehail_pricing.quotes.route_match_tolerance_m', 75);
            $quotedPickup = GeoPoint::from($quote->pickup_lat, $quote->pickup_lng);
            $quotedDropoff = GeoPoint::from($quote->dropoff_lat, $quote->dropoff_lng);

            if ($quotedPickup->metresTo($pickup) > $tolerance || $quotedDropoff->metresTo($dropoff) > $tolerance) {
                throw new QuoteRejected('route_mismatch');
            }

            if ($categoryKey !== null && $quote->category?->key !== $categoryKey) {
                throw new QuoteRejected('category_mismatch');
            }

            try {
                $redemption = $this->promotions->redeem($quote, $ride, $passenger);
            } catch (PromotionUnavailable) {
                throw new QuoteRejected('promotion_unavailable');
            }

            $quote->forceFill(['consumed_at' => now(), 'ride_id' => $ride->id])->save();

            $fare = RideFare::query()->create([
                'ride_id' => $ride->id,
                'fare_quote_id' => $quote->id,
                'rule_set_id' => $quote->rule_set_id,
                'rule_version' => $quote->rule_version,
                'ride_category_id' => $quote->ride_category_id,
                'currency' => $quote->currency,
                'quoted_distance_m' => $quote->route_distance_m,
                'quoted_duration_s' => $quote->route_duration_s,
                'demand_multiplier_bp' => $quote->demand_multiplier_bp,
                'zone_codes' => $quote->zone_codes,
                'promotion_discount_minor' => $quote->promotion_discount_minor,
                'promotion_redemption_id' => $redemption?->id,
                'estimated_components' => $quote->components,
                'estimated_total_minor' => $quote->total_minor,
            ]);

            AuditLog::record('pricing.quote_bound', $passenger, $ride, [
                'quote_id' => $quote->id,
                'total_minor' => $quote->total_minor,
                'currency' => $quote->currency,
                'rule_set_id' => $quote->rule_set_id,
                'rule_version' => $quote->rule_version,
            ]);

            return $fare;
        });
    }
}
