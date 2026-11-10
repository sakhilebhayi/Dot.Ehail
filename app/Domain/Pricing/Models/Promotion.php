<?php

namespace App\Domain\Pricing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount: by code or automatic, percent (with an optional cap) or fixed, with eligibility, a budget, a total
 * redemption limit and a per-user limit. Evaluated at quote time, redeemed only when a ride is created.
 *
 * @property int $id
 * @property ?string $code
 * @property string $name
 * @property string $kind
 * @property string $discount_type
 * @property ?int $percent_bp
 * @property ?int $amount_minor
 * @property ?int $max_discount_minor
 * @property string $currency
 * @property ?array $eligibility
 * @property ?CarbonInterface $starts_at
 * @property ?CarbonInterface $ends_at
 * @property ?int $budget_minor
 * @property int $spent_minor
 * @property ?int $max_redemptions
 * @property int $redemptions_count
 * @property int $per_user_limit
 * @property bool $is_active
 */
class Promotion extends Model
{
    protected $fillable = [
        'code', 'name', 'kind', 'discount_type', 'percent_bp', 'amount_minor', 'max_discount_minor', 'currency',
        'eligibility', 'starts_at', 'ends_at', 'budget_minor', 'max_redemptions', 'per_user_limit', 'is_active', 'created_by',
    ];

    protected $casts = [
        'percent_bp' => 'integer',
        'amount_minor' => 'integer',
        'max_discount_minor' => 'integer',
        'eligibility' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'budget_minor' => 'integer',
        'spent_minor' => 'integer',
        'max_redemptions' => 'integer',
        'redemptions_count' => 'integer',
        'per_user_limit' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Codes are matched case-insensitively by storing them upper case.
        static::saving(function (self $promotion): void {
            if ($promotion->code !== null) {
                $promotion->code = strtoupper(trim($promotion->code));
            }
        });
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function eligibility(string $key, mixed $default = null): mixed
    {
        return ($this->eligibility ?? [])[$key] ?? $default;
    }
}
