<?php

namespace App\Domain\Fleet\Models;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who works on a fleet: the owner (every area, members and settings) or a manager limited to the areas in
 * `scopes`. A revoked member keeps the row (history) and loses access.
 *
 * @property int $id
 * @property int $fleet_id
 * @property int $user_id
 * @property string $role
 * @property ?array<int, string> $scopes
 */
class FleetMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    /** Areas a manager can be given. Owners have all of them plus members and settings. */
    public const SCOPES = [
        'drivers' => 'Drivers and applications',
        'vehicles' => 'Vehicles and assignments',
        'maintenance' => 'Maintenance',
        'documents' => 'Document expiry',
        'earnings' => 'Earnings',
        'trips' => 'Trips and performance',
    ];

    protected $guarded = ['id'];

    protected $casts = ['scopes' => 'array', 'revoked_at' => 'datetime'];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function hasScope(string $scope): bool
    {
        return $this->isOwner() || in_array($scope, $this->scopes ?? [], true);
    }
}
