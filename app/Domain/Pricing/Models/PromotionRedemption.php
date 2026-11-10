<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $promotion_id
 * @property int $user_id
 * @property ?string $fare_quote_id
 * @property ?int $ride_id
 * @property int $discount_minor
 * @property string $currency
 * @property string $status
 */
class PromotionRedemption extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['discount_minor' => 'integer', 'reversed_at' => 'datetime'];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
