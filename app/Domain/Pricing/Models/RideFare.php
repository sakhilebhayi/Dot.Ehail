<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Money\Money;
use App\Models\Ride;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fare snapshot of one ride: what was quoted (estimated_*) and what was charged (final_*), each as components
 * that sum to the total, plus every adjustment with its reason code and plain-language text.
 *
 * @property int $id
 * @property int $ride_id
 * @property ?string $fare_quote_id
 * @property ?int $rule_set_id
 * @property ?int $rule_version
 * @property ?int $ride_category_id
 * @property string $currency
 * @property ?int $quoted_distance_m
 * @property ?int $quoted_duration_s
 * @property int $demand_multiplier_bp
 * @property ?array $zone_codes
 * @property int $promotion_discount_minor
 * @property ?int $promotion_redemption_id
 * @property array $estimated_components
 * @property int $estimated_total_minor
 * @property ?array $final_components
 * @property ?int $final_total_minor
 * @property ?string $basis
 * @property ?array $adjustments
 * @property ?int $actual_distance_m
 * @property ?int $actual_duration_s
 * @property ?int $waiting_seconds
 * @property ?string $distance_method
 * @property ?CarbonInterface $finalised_at
 * @property ?int $commission_plan_id
 * @property ?array $earnings
 */
class RideFare extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rule_set_id' => 'integer',
        'rule_version' => 'integer',
        'ride_category_id' => 'integer',
        'quoted_distance_m' => 'integer',
        'quoted_duration_s' => 'integer',
        'demand_multiplier_bp' => 'integer',
        'zone_codes' => 'array',
        'promotion_discount_minor' => 'integer',
        'estimated_components' => 'array',
        'estimated_total_minor' => 'integer',
        'final_components' => 'array',
        'final_total_minor' => 'integer',
        'adjustments' => 'array',
        'actual_distance_m' => 'integer',
        'actual_duration_s' => 'integer',
        'waiting_seconds' => 'integer',
        'finalised_at' => 'datetime',
        'commission_plan_id' => 'integer',
        'earnings' => 'array',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(PricingRuleSet::class, 'rule_set_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FareQuote::class, 'fare_quote_id');
    }

    public function isFinalised(): bool
    {
        return $this->finalised_at !== null;
    }

    public function estimatedTotal(): Money
    {
        return Money::of($this->estimated_total_minor, $this->currency);
    }

    public function finalTotal(): ?Money
    {
        return $this->final_total_minor === null ? null : Money::of($this->final_total_minor, $this->currency);
    }
}
