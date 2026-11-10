<?php

namespace App\Domain\Safety\Models;

use App\Models\RideRating;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A driver's appeal against one rating. pending -> upheld | rejected. */
class RatingAppeal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(RideRating::class, 'ride_rating_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
