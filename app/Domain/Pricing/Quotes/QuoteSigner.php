<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Pricing\Models\FareQuote;

/**
 * HMAC-SHA256 over everything a quote promises (who, where, which route, which tariff version, what price, until
 * when), keyed from APP_KEY. Any change to a stored quote row (by SQL or a bug) makes it unbookable.
 */
final class QuoteSigner
{
    public function sign(FareQuote $quote): string
    {
        return hash_hmac('sha256', $this->canonical($quote), $this->key());
    }

    public function verify(FareQuote $quote): bool
    {
        return hash_equals($this->sign($quote), (string) $quote->signature);
    }

    private function canonical(FareQuote $quote): string
    {
        $coordinate = fn ($v) => number_format((float) $v, 7, '.', '');

        return implode('|', [
            'v1',
            $quote->id,
            (string) $quote->passenger_id,
            $quote->purpose,
            $coordinate($quote->pickup_lat), $coordinate($quote->pickup_lng),
            $coordinate($quote->dropoff_lat), $coordinate($quote->dropoff_lng),
            (string) $quote->service_area_id,
            (string) $quote->ride_category_id,
            (string) $quote->rule_set_id,
            (string) $quote->rule_version,
            (string) $quote->route_distance_m,
            (string) $quote->route_duration_s,
            $quote->polyline_hash,
            json_encode($quote->zone_codes ?? []),
            json_encode($quote->components),
            (string) $quote->demand_multiplier_bp,
            (string) $quote->total_minor,
            $quote->currency,
            (string) $quote->promotion_id,
            (string) $quote->promotion_discount_minor,
            (string) $quote->expires_at->getTimestamp(),
        ]);
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'ehail.pricing.quote-signature', (string) config('app.key'));
    }
}
