<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Models\Concerns\VersionedOnPublish;
use Illuminate\Database\Eloquent\Model;

/**
 * What the platform keeps from a trip: percent_bp of the commissionable components plus fixed_minor, clamped to
 * [min, max] and never more than the commissionable amount. Resolved per market, category and fleet (a fleet plan
 * overrides the market plan). Versioned and frozen once published, like rule sets.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_example
 * @property string $country
 * @property string $currency
 * @property ?int $service_area_id
 * @property ?int $ride_category_id
 * @property ?int $fleet_id
 * @property int $percent_bp
 * @property int $fixed_minor
 * @property ?int $min_commission_minor
 * @property ?int $max_commission_minor
 * @property ?array $commissionable_codes
 */
class CommissionPlan extends Model
{
    use VersionedOnPublish;

    /**
     * Commission is charged on the trip fare: not on tolls (passed to the driver at cost), the booking fee, zone
     * fees or tax (platform or third-party money), and promotions are funded by the platform. Caps, rounding and
     * operator adjustments move the trip fare, so the driver shares them.
     */
    public const DEFAULT_COMMISSIONABLE = ['base', 'distance', 'time', 'waiting', 'minimum_adjustment', 'demand', 'recalculation_cap', 'rounding', 'operator_adjustment'];

    public const TERMS = [
        'name', 'country', 'currency', 'service_area_id', 'ride_category_id', 'fleet_id', 'percent_bp', 'fixed_minor',
        'min_commission_minor', 'max_commission_minor', 'commissionable_codes',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'version' => 'integer',
        'is_example' => 'boolean',
        'service_area_id' => 'integer',
        'ride_category_id' => 'integer',
        'fleet_id' => 'integer',
        'percent_bp' => 'integer',
        'fixed_minor' => 'integer',
        'min_commission_minor' => 'integer',
        'max_commission_minor' => 'integer',
        'commissionable_codes' => 'array',
        'effective_from' => 'datetime',
        'retired_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public function commissionableCodes(): array
    {
        return $this->commissionable_codes ?? self::DEFAULT_COMMISSIONABLE;
    }
}
