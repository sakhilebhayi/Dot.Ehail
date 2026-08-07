<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'license_number', 'id_number', 'status', 'is_online', 'rating', 'total_rides'];

    protected $casts = [
        'is_online' => 'boolean',
        'rating' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function activeVehicle(): ?Vehicle
    {
        return $this->vehicles()->where('is_active', true)->first();
    }
}
