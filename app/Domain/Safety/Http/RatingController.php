<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\Models\RatingAppeal;
use App\Domain\Safety\RatingModeration;
use App\Domain\Safety\TrustSignals;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\RideRating;
use App\Services\Ehail\AlreadyRated;
use App\Services\Ehail\RideRatings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ratings JSON API for the wave-2 apps: rate (one per trip per side, completed rides only,
 * rate-limited), the ratings I received (band plus visible comments, no rater names), appeals.
 */
class RatingController extends Controller
{
    public function rate(Request $request, Ride $ride, RideRatings $ratings): JsonResponse
    {
        abort_unless(Gate::allows('rate', $ride), 403, 'You can rate a ride once it is completed, and only as its passenger or driver.');

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $row = $ratings->rate($ride, $request->user(), (int) $validated['rating'], $validated['comment'] ?? null);
        } catch (AlreadyRated $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $row->refresh();

        return response()->json([
            'data' => ['id' => $row->id, 'rating' => $row->rating, 'comment_status' => $row->comment_status],
            'message' => $row->comment_status === 'held' ? 'Thanks. Your comment will be checked before anyone sees it.' : 'Thanks, your rating was saved.',
        ], 201);
    }

    public function received(Request $request): JsonResponse
    {
        $role = $request->query('role') === 'passenger' ? RideRating::ROLE_PASSENGER : RideRating::ROLE_DRIVER;
        $userId = $request->user()->id;
        $stats = TrustSignals::ratingStats($userId, $role);

        $recent = RideRating::query()
            ->where('rated_user_id', $userId)->where('rated_role', $role)
            ->orderByDesc('id')->limit(30)->get();

        $appeals = RatingAppeal::query()->whereIn('ride_rating_id', $recent->pluck('id'))->get()->keyBy('ride_rating_id');

        return response()->json([
            'band' => TrustSignals::band($stats['average'], $stats['count']),
            // The person rated may see their own average; other surfaces show the band only.
            'average' => $stats['average'] !== null ? round($stats['average'], 2) : null,
            'data' => $recent->map(fn (RideRating $r) => [
                'id' => $r->id,
                'ride_id' => $r->ride_id,
                'rating' => $r->rating,
                'comment' => $r->comment_status === 'visible' ? $r->comment : null,
                'comment_hidden' => $r->comment !== null && $r->comment_status !== 'visible',
                'excluded' => $r->excluded_at !== null,
                'appeal' => isset($appeals[$r->id]) ? ['status' => $appeals[$r->id]->status, 'decision_note' => $appeals[$r->id]->decision_note] : null,
                'can_appeal' => $role === RideRating::ROLE_DRIVER && ! isset($appeals[$r->id]) && $r->created_at->gte(now()->subDays(RatingModeration::APPEAL_WINDOW_DAYS)),
                'at' => $r->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function appeal(Request $request, RideRating $rating, RatingModeration $moderation): JsonResponse
    {
        abort_unless($rating->rated_user_id === $request->user()->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        $appeal = $moderation->appeal($rating, $request->user(), $validated['reason']);

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]], 201);
    }
}
