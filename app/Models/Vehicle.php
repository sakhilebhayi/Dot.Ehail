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

    protected $fillable = ['driver_profile_id', 'make', 'model', 'year', 'color', 'plate_number', 'type', 'seats', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'seats' => 'integer'];

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
