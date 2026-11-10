<?php

namespace App\Domain\Ops\Live;

use App\Models\Ride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Stuck" (D-1005): a live ride whose state has not moved for longer than an honest operational limit. Measured
 * from the ride's own stamps (the moment it entered its current state), never guessed:
 *   searching/requested: still looking more than 2 minutes past the search window (the dispatcher should have
 *     expired it; usually means nothing is polling and the scheduler is not running);
 *   driver_assigned / driver_arriving: 20 minutes without reaching the pickup;
 *   driver_arrived: 15 minutes at the pickup without the trip starting or a no-show;
 *   in_progress: 3 hours on the trip.
 */
class StuckRides
{
    /** @var array<string, int> status => minutes in that state before it counts as stuck */
    public const LIMITS = [
        'driver_assigned' => 20,
        'driver_arriving' => 20,
        'driver_arrived' => 15,
        'in_progress' => 180,
    ];

    public function searchLimitMinutes(): int
    {
        return max(1, (int) config('ehail.ride_expiry_minutes', 10)) + 2;
    }

    /** When the ride entered its current state (null when the ride carries no stamp for it). */
    public function enteredStateAt(Ride $ride): ?Carbon
    {
        return match ($ride->status) {
            'requested', 'searching' => $ride->search_started_at ?? $ride->requested_at ?? $ride->created_at,
            'driver_assigned' => $ride->accepted_at,
            'driver_arriving' => $ride->driver_arriving_at ?? $ride->accepted_at,
            'driver_arrived' => $ride->arrived_at,
            'in_progress' => $ride->started_at,
            'needs_review' => $ride->needs_review_at,
            default => null,
        };
    }

    public function limitMinutes(string $status): ?int
    {
        return in_array($status, ['requested', 'searching'], true) ? $this->searchLimitMinutes() : (self::LIMITS[$status] ?? null);
    }

    public function isStuck(Ride $ride, ?Carbon $now = null): bool
    {
        $limit = $this->limitMinutes((string) $ride->status);
        $since = $this->enteredStateAt($ride);

        return $limit !== null && $since !== null && $since->lt(($now ?? now())->copy()->subMinutes($limit));
    }

    /** @return Collection<int, Ride> */
    public function find(int $limit = 50): Collection
    {
        $now = now();

        // Live rides are few (one per active passenger); the state stamps decide, in PHP, so every engine agrees.
        return Ride::query()
            ->whereIn('status', array_merge(array_keys(self::LIMITS), ['requested', 'searching']))
            ->with(['passenger:id,name', 'driver:id,name'])
            ->orderBy('id')
            ->limit(2000)
            ->get()
            ->filter(fn (Ride $ride) => $this->isStuck($ride, $now))
            ->take($limit)
            ->values();
    }
}
