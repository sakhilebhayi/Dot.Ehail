<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Pricing\Support\TimeWindows;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product a passenger can choose (Economy, Comfort, XL, ...). Which vehicles may serve it lives in
 * category_vehicle_rules; what it costs lives in pricing rule sets.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property string $service_kind
 * @property int $capacity
 * @property int $luggage
 * @property ?int $driver_rating_floor
 * @property ?array $required_documents
 * @property bool $fleet_only
 * @property ?array $availability
 * @property ?array $policies
 * @property ?string $legacy_vehicle_type
 * @property bool $is_active
 * @property int $display_order
 * @property ?string $icon_key
 */
class RideCategory extends Model
{
    protected $table = 'ride_categories';

    protected $fillable = [
        'key', 'name', 'description', 'service_kind', 'capacity', 'luggage', 'driver_rating_floor',
        'required_documents', 'fleet_only', 'availability', 'policies', 'legacy_vehicle_type',
        'is_active', 'display_order', 'icon_key',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'luggage' => 'integer',
        'driver_rating_floor' => 'integer',
        'required_documents' => 'array',
        'fleet_only' => 'boolean',
        'availability' => 'array',
        'policies' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * @return HasMany<CategoryVehicleRule, $this>
     */
    public function vehicleRules(): HasMany
    {
        return $this->hasMany(CategoryVehicleRule::class, 'ride_category_id');
    }

    public function scopeActiveRides(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('service_kind', 'ride')->orderBy('display_order')->orderBy('id');
    }

    /**
     * Whether the category is offered in this service area at this moment (availability json). A null
     * service-area list means every area; null windows mean all the time.
     */
    public function isOfferedIn(?int $serviceAreaId, CarbonInterface $at, string $timezone): bool
    {
        $availability = $this->availability ?? [];
        $areas = $availability['service_area_ids'] ?? null;

        if (is_array($areas) && $areas !== [] && ($serviceAreaId === null || ! in_array($serviceAreaId, array_map('intval', $areas), true))) {
            return false;
        }

        return TimeWindows::contains($availability['windows'] ?? null, $at, $timezone);
    }

    public function policy(string $key, mixed $default = null): mixed
    {
        return ($this->policies ?? [])[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'capacity' => $this->capacity,
            'luggage' => $this->luggage,
            'icon_key' => $this->icon_key,
            'policies' => [
                'cancellation_free_minutes' => $this->policy('cancellation_free_minutes'),
                'max_wait_minutes' => $this->policy('max_wait_minutes'),
            ],
        ];
    }
}
