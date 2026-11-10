<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use App\Domain\Pricing\Money\Money;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server-side price promise: bound to one passenger, one road route and one rule-set version, signed, and
 * expiring. It is written once; the only later change is being consumed by exactly one ride.
 *
 * @property string $id
 * @property ?int $passenger_id
 * @property string $purpose
 * @property string $pickup_lat
 * @property string $pickup_lng
 * @property string $dropoff_lat
 * @property string $dropoff_lng
 * @property ?int $service_area_id
 * @property int $ride_category_id
 * @property int $rule_set_id
 * @property int $rule_version
 * @property int $route_distance_m
 * @property int $route_duration_s
 * @property string $route_provider
 * @property string $polyline_hash
 * @property ?array $zone_codes
 * @property array $components
 * @property int $demand_multiplier_bp
 * @property int $total_minor
 * @property string $currency
 * @property ?int $promotion_id
 * @property int $promotion_discount_minor
 * @property bool $is_example
 * @property CarbonInterface $expires_at
 * @property string $signature
 * @property ?CarbonInterface $consumed_at
 * @property ?int $ride_id
 * @property CarbonInterface $created_at
 */
class FareQuote extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'service_area_id' => 'integer',
        'ride_category_id' => 'integer',
        'rule_set_id' => 'integer',
        'rule_version' => 'integer',
        'route_distance_m' => 'integer',
        'route_duration_s' => 'integer',
        'zone_codes' => 'array',
        'components' => 'array',
        'demand_multiplier_bp' => 'integer',
        'total_minor' => 'integer',
        'promotion_id' => 'integer',
        'promotion_discount_minor' => 'integer',
        'is_example' => 'boolean',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'ride_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $quote): void {
            $changed = array_diff(array_keys($quote->getDirty()), ['updated_at']);
            $consuming = $quote->getOriginal('consumed_at') === null && array_diff($changed, ['consumed_at', 'ride_id']) === [];

            if (! $consuming) {
                throw new ImmutablePricingRecord('A fare quote cannot be changed after it is issued.');
            }
        });
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class, 'ride_category_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(PricingRuleSet::class, 'rule_set_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
