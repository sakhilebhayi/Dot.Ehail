<?php

namespace App\Domain\Pricing\Promotions;

use App\Domain\Pricing\Exceptions\PromotionUnavailable;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\PromotionRedemption;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a quote's promotion when the ride is created. Two guards make limits hold under concurrency on every
 * database:
 *  1. the promotion row is locked (SELECT ... FOR UPDATE; SQLite serialises writers anyway), so per-user counts are
 *     read and written by one transaction at a time;
 *  2. the counters move with ONE conditional UPDATE (redemptions_count < max AND spent + discount <= budget), so even
 *     without the lock two redemptions can never both take the last slot or overspend the budget.
 */
final class PromotionRedeemer
{
    public function redeem(FareQuote $quote, Ride $ride, User $passenger): ?PromotionRedemption
    {
        if ($quote->promotion_id === null || $quote->promotion_discount_minor <= 0) {
            return null;
        }

        return DB::transaction(function () use ($quote, $ride, $passenger) {
            $promotion = Promotion::query()->whereKey($quote->promotion_id)->lockForUpdate()->first();

            if ($promotion === null || ! $promotion->is_active) {
                throw new PromotionUnavailable('inactive');
            }

            $now = now();

            if (($promotion->starts_at !== null && $now->lessThan($promotion->starts_at)) || ($promotion->ends_at !== null && $now->greaterThanOrEqualTo($promotion->ends_at))) {
                throw new PromotionUnavailable('outside_window');
            }

            $used = PromotionRedemption::query()->where('promotion_id', $promotion->id)->where('user_id', $passenger->id)->where('status', 'applied')->count();

            if ($used >= $promotion->per_user_limit) {
                throw new PromotionUnavailable('per_user_limit');
            }

            $discount = $quote->promotion_discount_minor;

            $updated = Promotion::query()
                ->whereKey($promotion->id)
                ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions_count', '<', 'max_redemptions'))
                ->where(fn ($q) => $q->whereNull('budget_minor')->orWhereRaw('spent_minor + ? <= budget_minor', [$discount]))
                ->update([
                    'redemptions_count' => DB::raw('redemptions_count + 1'),
                    'spent_minor' => DB::raw('spent_minor + '.(int) $discount),
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new PromotionUnavailable('limit_reached');
            }

            return PromotionRedemption::query()->create([
                'promotion_id' => $promotion->id,
                'user_id' => $passenger->id,
                'fare_quote_id' => $quote->id,
                'ride_id' => $ride->id,
                'discount_minor' => $discount,
                'currency' => $quote->currency,
                'status' => 'applied',
            ]);
        });
    }

    /**
     * Give the slot and budget back (e.g. the ride was cancelled before pickup at no charge).
     */
    public function reverse(PromotionRedemption $redemption): void
    {
        DB::transaction(function () use ($redemption) {
            $locked = PromotionRedemption::query()->whereKey($redemption->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'applied') {
                return;
            }

            $locked->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();

            Promotion::query()->whereKey($locked->promotion_id)->update([
                'redemptions_count' => DB::raw('CASE WHEN redemptions_count > 0 THEN redemptions_count - 1 ELSE 0 END'),
                'spent_minor' => DB::raw('CASE WHEN spent_minor >= '.(int) $locked->discount_minor.' THEN spent_minor - '.(int) $locked->discount_minor.' ELSE 0 END'),
                'updated_at' => now(),
            ]);
        });
    }
}
