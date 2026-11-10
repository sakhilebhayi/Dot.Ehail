<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Models\Concerns\VersionedOnPublish;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a tariff. Every term of the fare formula (docs/R46_PRICING.md) is a column here, so a quote
 * made against (id, version) can always be recomputed exactly.
 *
 * @property int $id
 * @property string $name
 * @property ?string $notes
 * @property bool $is_example
 * @property string $country
 * @property string $currency
 * @property ?int $service_area_id
 * @property ?string $zone_slug
 * @property ?int $ride_category_id
 * @property ?array $time_windows
 * @property string $timezone
 * @property int $base_minor
 * @property int $per_km_minor
 * @property int $per_minute_minor
 * @property int $minimum_minor
 * @property int $booking_fee_minor
 * @property int $wait_free_minutes
 * @property int $wait_per_minute_minor
 * @property int $cancel_free_minutes
 * @property int $cancel_fee_minor
 * @property int $no_show_fee_minor
 * @property ?array $zone_fees
 * @property string $toll_mode
 * @property bool $demand_enabled
 * @property int $demand_cap_bp
 * @property string $tax_mode
 * @property int $tax_rate_bp
 * @property ?string $tax_label
 * @property int $rounding_unit_minor
 * @property string $rounding_mode
 * @property int $distance_tolerance_bp
 * @property int $duration_tolerance_bp
 * @property int $overrun_cap_bp
 * @property ?CarbonInterface $published_at
 * @property ?int $published_by
 * @property ?int $created_by
 */
class PricingRuleSet extends Model
{
    use VersionedOnPublish;

    /**
     * Terms an operator may set on a draft (everything except identity, lifecycle and audit columns).
     */
    public const TERMS = [
        'name', 'notes', 'country', 'currency', 'service_area_id', 'zone_slug', 'ride_category_id', 'time_windows', 'timezone',
        'base_minor', 'per_km_minor', 'per_minute_minor', 'minimum_minor', 'booking_fee_minor',
        'wait_free_minutes', 'wait_per_minute_minor', 'cancel_free_minutes', 'cancel_fee_minor', 'no_show_fee_minor',
        'zone_fees', 'toll_mode', 'demand_enabled', 'demand_cap_bp', 'tax_mode', 'tax_rate_bp', 'tax_label',
        'rounding_unit_minor', 'rounding_mode', 'distance_tolerance_bp', 'duration_tolerance_bp', 'overrun_cap_bp',
    ];

    protected $table = 'pricing_rule_sets';

    protected $guarded = ['id'];

    protected $casts = [
        'version' => 'integer',
        'is_example' => 'boolean',
        'service_area_id' => 'integer',
        'ride_category_id' => 'integer',
        'time_windows' => 'array',
        'zone_fees' => 'array',
        'base_minor' => 'integer',
        'per_km_minor' => 'integer',
        'per_minute_minor' => 'integer',
        'minimum_minor' => 'integer',
        'booking_fee_minor' => 'integer',
        'wait_free_minutes' => 'integer',
        'wait_per_minute_minor' => 'integer',
        'cancel_free_minutes' => 'integer',
        'cancel_fee_minor' => 'integer',
        'no_show_fee_minor' => 'integer',
        'demand_enabled' => 'boolean',
        'demand_cap_bp' => 'integer',
        'tax_rate_bp' => 'integer',
        'rounding_unit_minor' => 'integer',
        'distance_tolerance_bp' => 'integer',
        'duration_tolerance_bp' => 'integer',
        'overrun_cap_bp' => 'integer',
        'effective_from' => 'datetime',
        'retired_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<RideCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class, 'ride_category_id');
    }

    public function money(int $minor): Money
    {
        return Money::of($minor, $this->currency);
    }

    public function roundingMode(): RoundingMode
    {
        return RoundingMode::tryFrom($this->rounding_mode) ?? RoundingMode::HalfUp;
    }

    /**
     * @return array{pickup_minor: int, dropoff_minor: int, label: string}|null
     */
    public function zoneFee(string $feeCode): ?array
    {
        $fee = ($this->zone_fees ?? [])[$feeCode] ?? null;

        if (! is_array($fee)) {
            return null;
        }

        return [
            'pickup_minor' => (int) ($fee['pickup_minor'] ?? 0),
            'dropoff_minor' => (int) ($fee['dropoff_minor'] ?? 0),
            'label' => (string) ($fee['label'] ?? $feeCode),
        ];
    }

    /**
     * Public summary of the tariff (what the passenger can be shown as "how this is priced").
     *
     * @return array<string, mixed>
     */
    public function toTariffArray(): array
    {
        return [
            'rule_set_id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'is_example' => $this->is_example,
            'currency' => $this->currency,
            'base' => $this->money($this->base_minor),
            'per_km' => $this->money($this->per_km_minor),
            'per_minute' => $this->money($this->per_minute_minor),
            'minimum' => $this->money($this->minimum_minor),
            'booking_fee' => $this->money($this->booking_fee_minor),
            'wait_free_minutes' => $this->wait_free_minutes,
            'wait_per_minute' => $this->money($this->wait_per_minute_minor),
            'cancel_free_minutes' => $this->cancel_free_minutes,
            'cancel_fee' => $this->money($this->cancel_fee_minor),
            'no_show_fee' => $this->money($this->no_show_fee_minor),
            'toll_mode' => $this->toll_mode,
            'demand_enabled' => $this->demand_enabled,
            'demand_cap' => $this->demand_cap_bp,
            'tax_mode' => $this->tax_mode,
            'tax_rate_bp' => $this->tax_rate_bp,
            'tax_label' => $this->tax_label,
        ];
    }
}
