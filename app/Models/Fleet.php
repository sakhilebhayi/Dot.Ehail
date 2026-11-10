<?php

namespace App\Models;

use App\Domain\Fleet\Models\FleetMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fleet extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const KIND_INDEPENDENT = 'independent';

    public const KIND_COMPANY = 'company';

    protected $fillable = [
        'name', 'owner_user_id', 'status', 'kind', 'trading_name', 'registration_number',
        'contact_email', 'contact_phone', 'base_city', 'payee_mode',
    ];

    protected $casts = [
        'identity_review_granted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function isIndependent(): bool
    {
        return $this->kind === self::KIND_INDEPENDENT;
    }

    /** A platform operator let this fleet's owner see masked identity numbers when reviewing applications. */
    public function identityReviewGranted(): bool
    {
        return $this->identity_review_granted_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function driverProfiles(): HasMany
    {
        return $this->hasMany(DriverProfile::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(FleetMember::class);
    }
}
