<?php

namespace App\Domain\Earnings\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable driver incentive. Kinds and their rules (json):
 *  trip_count    {"trips": 20}                      completed trips in the window
 *  online_hours  {"hours": 30, "min_acceptance": 0.8} online time + acceptance (needs dispatch data)
 *  area          {"trips": 10, "polygon": [[lat,lng],...]} completed trips picked up inside the polygon
 *  referral      {"referrals": 3}                   qualified referrals (needs a referral system)
 *  milestone     {"lifetime_trips": 100}            lifetime completed trips, awarded once
 * Optional for every kind: {"min_rating": 4.5} (driver profile rating at evaluation time).
 */
class IncentiveProgram extends Model
{
    public const KINDS = ['trip_count', 'online_hours', 'area', 'referral', 'milestone'];

    public const WINDOWS = ['day', 'week', 'month', 'program'];

    protected $guarded = ['id'];

    protected $casts = [
        'rules' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reward_minor' => 'integer',
        'budget_minor' => 'integer',
        'awarded_minor' => 'integer',
    ];

    public function progress(): HasMany
    {
        return $this->hasMany(IncentiveProgress::class);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function budgetRemaining(): ?int
    {
        return $this->budget_minor === null ? null : max(0, $this->budget_minor - $this->awarded_minor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'rules' => $this->rules,
            'reward_minor' => $this->reward_minor,
            'currency' => $this->currency,
            'window' => $this->window,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
