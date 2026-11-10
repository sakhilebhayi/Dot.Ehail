<?php

namespace App\Domain\Pricing\Promotions;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Engine\DiscountTerms;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\PromotionRedemption;
use App\Domain\Pricing\Support\TimeWindows;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Decides at QUOTE time whether a promotion applies and on what terms. Nothing is redeemed here: redemption happens
 * once, inside the ride-creation transaction (PromotionRedeemer), where limits are re-checked under a lock.
 *
 * A typed code is evaluated alone. Without a code, the best eligible automatic promotion applies (largest discount,
 * lowest id on ties). Anonymous estimates never apply promotions: per-user limits cannot be checked for nobody.
 */
final class PromotionEvaluator
{
    /**
     * @param  callable(DiscountTerms): int  $discountFor  the discount a set of terms would give on this fare
     */
    public function choose(?string $code, ?User $passenger, RideCategory $category, ?int $serviceAreaId, string $currency, int $prePromotionMinor, CarbonInterface $at, string $timezone, callable $discountFor): PromotionDecision
    {
        if ($passenger === null) {
            return new PromotionDecision(null, null, $code !== null && $code !== '' ? 'Sign in to use a promotion code.' : null);
        }

        if ($code !== null && trim($code) !== '') {
            $promotion = Promotion::query()->where('code', strtoupper(trim($code)))->first();

            if ($promotion === null || $promotion->kind !== 'code') {
                return new PromotionDecision(null, null, 'That promotion code is not valid.');
            }

            $failure = $this->failure($promotion, $passenger, $category, $serviceAreaId, $currency, $prePromotionMinor, $at, $timezone);

            if ($failure !== null) {
                return new PromotionDecision(null, null, $failure);
            }

            return new PromotionDecision($promotion, $this->terms($promotion), null);
        }

        $best = null;
        $bestDiscount = 0;

        $automatic = Promotion::query()->where('kind', 'automatic')->where('is_active', true)->where('currency', $currency)->orderBy('id')->get();

        foreach ($automatic as $promotion) {
            if ($this->failure($promotion, $passenger, $category, $serviceAreaId, $currency, $prePromotionMinor, $at, $timezone) !== null) {
                continue;
            }

            $discount = $discountFor($this->terms($promotion));

            if ($discount > $bestDiscount) {
                $best = $promotion;
                $bestDiscount = $discount;
            }
        }

        return $best === null ? new PromotionDecision(null, null, null) : new PromotionDecision($best, $this->terms($best), null);
    }

    /**
     * Why the promotion does not apply, in words the passenger can act on; null when it applies.
     */
    public function failure(Promotion $promotion, User $passenger, RideCategory $category, ?int $serviceAreaId, string $currency, int $prePromotionMinor, CarbonInterface $at, string $timezone): ?string
    {
        if (! $promotion->is_active) {
            return 'This promotion has ended.';
        }

        if ($promotion->currency !== $currency) {
            return 'This promotion is not valid in this market.';
        }

        if ($promotion->starts_at !== null && $at->lessThan($promotion->starts_at)) {
            return 'This promotion has not started yet.';
        }

        if ($promotion->ends_at !== null && $at->greaterThanOrEqualTo($promotion->ends_at)) {
            return 'This promotion has ended.';
        }

        if ($promotion->max_redemptions !== null && $promotion->redemptions_count >= $promotion->max_redemptions) {
            return 'This promotion has been fully used.';
        }

        if ($promotion->budget_minor !== null && $promotion->spent_minor >= $promotion->budget_minor) {
            return 'This promotion has been fully used.';
        }

        $categories = $promotion->eligibility('category_keys');

        if (is_array($categories) && $categories !== [] && ! in_array($category->key, $categories, true)) {
            return 'This promotion does not apply to '.$category->name.'.';
        }

        $areas = $promotion->eligibility('service_area_ids');

        if (is_array($areas) && $areas !== [] && ($serviceAreaId === null || ! in_array($serviceAreaId, array_map('intval', $areas), true))) {
            return 'This promotion does not apply in this area.';
        }

        if (! TimeWindows::contains($promotion->eligibility('time_windows'), $at, (string) $promotion->eligibility('timezone', $timezone))) {
            return 'This promotion does not apply at this time.';
        }

        $minimum = $promotion->eligibility('min_fare_minor');

        if ($minimum !== null && $prePromotionMinor < (int) $minimum) {
            return 'This promotion needs a higher fare.';
        }

        if ($promotion->eligibility('first_ride_only') && Ride::query()->where('passenger_id', $passenger->id)->where('status', 'completed')->exists()) {
            return 'This promotion is for a first ride only.';
        }

        $used = PromotionRedemption::query()->where('promotion_id', $promotion->id)->where('user_id', $passenger->id)->where('status', 'applied')->count();

        if ($used >= $promotion->per_user_limit) {
            return 'You have already used this promotion.';
        }

        return null;
    }

    /**
     * Discount terms, with the cap also limited to what is left of the budget.
     */
    public function terms(Promotion $promotion): DiscountTerms
    {
        $cap = $promotion->max_discount_minor;

        if ($promotion->budget_minor !== null) {
            $remaining = max(0, $promotion->budget_minor - $promotion->spent_minor);
            $cap = $cap === null ? $remaining : min($cap, $remaining);
        }

        return $promotion->discount_type === 'percent'
            ? new DiscountTerms($promotion->name, (int) $promotion->percent_bp, null, $cap)
            : new DiscountTerms($promotion->name, null, (int) $promotion->amount_minor, $cap);
    }
}
