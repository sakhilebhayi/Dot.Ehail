<?php

namespace App\Services\Ehail;

use App\Enums\RideActor;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a rating and, when the rated person was the driver, brings the
 * driver's average and ride count up to date -- both in one transaction, so a
 * failure in either leaves neither behind.
 */
class RideRatings
{
    public function __construct(private DriverStats $stats) {}

    public function rate(Ride $ride, User $rater, int $rating, ?string $comment = null): RideRating
    {
        [$ratedUserId, $ratedRole] = match ($ride->actorFor($rater)) {
            RideActor::Passenger => [$ride->driver_id, RideRating::ROLE_DRIVER],
            RideActor::Driver => [$ride->passenger_id, RideRating::ROLE_PASSENGER],
            default => throw new \DomainException('Only the passenger and the driver can rate a ride.'),
        };

        return DB::transaction(function () use ($ride, $rater, $rating, $comment, $ratedUserId, $ratedRole) {
            if (RideRating::where('ride_id', $ride->id)->where('rated_by', $rater->id)->exists()) {
                throw new AlreadyRated;
            }

            try {
                $row = RideRating::create([
                    'ride_id' => $ride->id,
                    'rated_by' => $rater->id,
                    'rated_user_id' => $ratedUserId,
                    'rated_role' => $ratedRole,
                    'rating' => $rating,
                    'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two taps at once: the unique index decided who was first.
                throw new AlreadyRated;
            }

            if ($ratedRole === RideRating::ROLE_DRIVER) {
                $this->stats->refresh($ratedUserId);
            }

            return $row;
        });
    }
}
