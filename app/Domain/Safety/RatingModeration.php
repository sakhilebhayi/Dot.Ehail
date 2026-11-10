<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Models\RatingAppeal;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Models\AuditLog;
use App\Models\RideRating;
use App\Models\User;
use App\Services\Ehail\DriverStats;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rating moderation. Comments with held terms are held for an operator (never shown until
 * released); operators can hide an abusive comment (the star value stays); a driver can appeal a
 * rating once within 30 days, and an upheld appeal removes it from the average (the row stays on
 * record). Nothing here suspends anyone.
 */
class RatingModeration
{
    public const APPEAL_WINDOW_DAYS = 30;

    public function __construct(private readonly DriverStats $stats) {}

    /** Called when a rating is created. */
    public function screen(RideRating $rating): void
    {
        if ($rating->comment === null || ! $this->containsHeldTerm($rating->comment)) {
            return;
        }

        RideRating::query()->whereKey($rating->id)->update(['comment_status' => 'held', 'moderation_reason' => 'held_terms']);
    }

    public function containsHeldTerm(string $text): bool
    {
        foreach ((array) config('ehail_safety.ratings.held_terms', []) as $term) {
            if (preg_match('/\b'.preg_quote((string) $term, '/').'\b/iu', $text)) {
                return true;
            }
        }

        return false;
    }

    public function hide(RideRating $rating, User $operator, string $reason): RideRating
    {
        return $this->setStatus($rating, $operator, 'hidden', $reason);
    }

    public function release(RideRating $rating, User $operator): RideRating
    {
        return $this->setStatus($rating, $operator, 'visible', 'released');
    }

    public function appeal(RideRating $rating, User $driver, string $reason): RatingAppeal
    {
        if ($rating->rated_user_id !== $driver->id || $rating->rated_role !== RideRating::ROLE_DRIVER) {
            throw ValidationException::withMessages(['rating' => 'You can only appeal ratings you received as a driver.']);
        }

        if ($rating->created_at->lt(now()->subDays(self::APPEAL_WINDOW_DAYS))) {
            throw ValidationException::withMessages(['rating' => 'Ratings can be appealed within '.self::APPEAL_WINDOW_DAYS.' days.']);
        }

        try {
            return RatingAppeal::create([
                'ride_rating_id' => $rating->id,
                'user_id' => $driver->id,
                'reason' => mb_substr(trim($reason), 0, 2000),
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rating' => 'This rating has already been appealed.']);
        }
    }

    /**
     * @param  'upheld'|'rejected'  $decision
     */
    public function decideAppeal(RatingAppeal $appeal, User $operator, string $decision, ?string $note): RatingAppeal
    {
        if (! in_array($decision, ['upheld', 'rejected'], true)) {
            throw new SafetyActionRefused('Unknown decision.');
        }

        $decided = DB::transaction(function () use ($appeal, $operator, $decision, $note) {
            $locked = RatingAppeal::query()->lockForUpdate()->findOrFail($appeal->id);

            if ($locked->status !== 'pending') {
                throw new SafetyActionRefused('This appeal has already been decided.');
            }

            $locked->forceFill(['status' => $decision, 'decided_by' => $operator->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            if ($decision === 'upheld') {
                RideRating::query()->whereKey($locked->ride_rating_id)->update(['excluded_at' => now()]);
            }

            AuditLog::record('rating_appeal.'.$decision, $operator, $locked, ['ride_rating_id' => $locked->ride_rating_id]);

            return $locked;
        });

        if ($decision === 'upheld') {
            $this->stats->refresh($decided->user_id);
        }

        User::find($decided->user_id)?->notify(new SafetyUpdateNotification(
            'rating_appeal_decided',
            $decision === 'upheld' ? 'Appeal upheld' : 'Appeal not upheld',
            $decision === 'upheld' ? 'The rating no longer counts towards your average.' : 'The rating stays. '.mb_substr((string) $note, 0, 300),
            ['appeal_id' => $decided->id],
        ));

        return $decided;
    }

    private function setStatus(RideRating $rating, User $operator, string $status, string $reason): RideRating
    {
        $rating->forceFill(['comment_status' => $status, 'moderated_by' => $operator->id, 'moderated_at' => now(), 'moderation_reason' => mb_substr($reason, 0, 40)])->save();
        AuditLog::record('ride_rating.comment_'.$status, $operator, $rating, ['reason' => $reason]);

        return $rating;
    }
}
