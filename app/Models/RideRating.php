<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's rating of the other party on a completed ride. At most one per
 * (ride, rater); the 1-5 range is enforced by the database as well as by the
 * request. rated_role is the role the rated person played on that ride, so a
 * driver's own riding never leaks into their driver average.
 */
class RideRating extends Model
{
    public const ROLE_DRIVER = 'driver';

    public const ROLE_PASSENGER = 'passenger';

    protected $fillable = ['ride_id', 'rated_by', 'rated_user_id', 'rated_role', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }

    public function ratedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_user_id');
    }
}
