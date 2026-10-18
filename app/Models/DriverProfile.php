<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use App\Support\DriverIdentifiers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasFactory, HasUserScope;

    protected $fillable = [
        'user_id',
        'fleet_id',
        'license_number',
        'id_number',
        'status',
        'is_online',
        'rating',
        'total_rides',
        'rejected_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * The identifiers are encrypted at rest (the `encrypted` casts below) and
     * must never leave through serialisation; their blind indexes stay in the
     * database.
     */
    protected $hidden = ['license_number', 'id_number', 'license_number_hash', 'id_number_hash'];

    protected $casts = [
        'license_number' => 'encrypted',
        'id_number' => 'encrypted',
        'is_online' => 'boolean',
        'rating' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep the blind indexes in step with the encrypted numbers: set on
        // create, refreshed only when a number actually changes.
        static::saving(function (DriverProfile $profile): void {
            foreach (['license_number', 'id_number'] as $column) {
                if (! $profile->exists || $profile->isDirty($column)) {
                    $profile->{$column.'_hash'} = DriverIdentifiers::blindIndex($profile->{$column});
                }
            }
        });
    }

    /**
     * Whether another profile already holds this licence number (ignoring
     * case and surrounding whitespace). Bypasses HasUserScope on purpose.
     */
    public static function licenseNumberTaken(string $value): bool
    {
        return static::indexTaken('license_number_hash', $value);
    }

    public static function idNumberTaken(string $value): bool
    {
        return static::indexTaken('id_number_hash', $value);
    }

    private static function indexTaken(string $column, string $value): bool
    {
        $hash = DriverIdentifiers::blindIndex($value);

        return $hash !== null && static::withoutGlobalScope('user')->where($column, $hash)->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function activeVehicle(): ?Vehicle
    {
        return $this->vehicles()->where('is_active', true)->first();
    }

    /**
     * The active vehicle, but only if it may serve a ride requested in
     * $rideTier (see config/ehail.php vehicle_tiers).
     */
    public function activeVehicleFor(string $rideTier): ?Vehicle
    {
        $vehicle = $this->activeVehicle();

        return $vehicle?->canServe($rideTier) ? $vehicle : null;
    }
}
