<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = ['driver_profile_id', 'make', 'model', 'year', 'color', 'plate_number', 'type', 'seats', 'is_active', 'fleet_id', 'owned_by', 'doors', 'features', 'odometer_km'];

    protected $casts = [
        'is_active' => 'boolean', 'seats' => 'integer', 'doors' => 'integer', 'features' => 'array',
        'odometer_km' => 'integer', 'out_of_service_at' => 'datetime', 'retired_at' => 'datetime',
    ];

    /**
     * Plates are stored trimmed, single-spaced and upper case so "ca 123 456"
     * and "CA 123 456" are the same plate.
     */
    public static function normalizePlate(string $plate): string
    {
        return Str::upper(trim((string) preg_replace('/\s+/', ' ', $plate)));
    }

    protected function plateNumber(): Attribute
    {
        return Attribute::set(fn (string $value) => static::normalizePlate($value));
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    /**
     * The driver profile holding this vehicle, for fleet pages: DriverProfile's per-user scope would hide every
     * holder who is not the person looking.
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id')->withoutGlobalScope('user');
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    /** Held by the fleet (assignable to permitted drivers), not the private car of one driver. */
    public function isFleetOwned(): bool
    {
        return $this->owned_by === 'fleet';
    }

    public function isOutOfService(): bool
    {
        return $this->out_of_service_at !== null;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /**
     * Whether this vehicle may take a ride requested in $rideTier
     * (config/ehail.php vehicle_tiers: requested tier => vehicle types).
     */
    public function canServe(string $rideTier): bool
    {
        return in_array($this->type, config("ehail.vehicle_tiers.{$rideTier}", [$rideTier]), true);
    }

    /**
     * The ride tiers this vehicle can serve.
     *
     * @return list<string>
     */
    public function servableTiers(): array
    {
        return array_values(array_keys(array_filter(
            config('ehail.vehicle_tiers'),
            fn (array $types) => in_array($this->type, $types, true),
        )));
    }
}
