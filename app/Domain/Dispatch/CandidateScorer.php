<?php

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Dispatch\Seams\StraightLineEta;
use App\Enums\RideStatus;
use App\Models\Ride;
use Illuminate\Support\Carbon;

/**
 * Ranks eligible drivers for one ride. Cost in seconds-equivalent, lowest
 * wins (docs/R46_DISPATCH.md "Scoring"):
 *
 *   cost = w_eta x eta_s
 *        - idle_weight x min(idle_s, idle_cap_s)                   (waiting longest helps)
 *        + clamp(s_per_trip x (trips_today - candidates' median), +-cap)  (evens out the day)
 *        + cooldown_s while inside a decline cooldown              (capped, temporary, never exclusion)
 *
 * Ties break on longer idle, then fewer trips today, then lower user id, so
 * the order is deterministic and testable. Every term and weight is returned
 * for ride_offers.score_terms, so the policy can be measured afterwards.
 */
class CandidateScorer
{
    public function __construct(
        private readonly PickupEtaEstimator $eta,
        private readonly StraightLineEta $fallbackEta,
    ) {}

    /**
     * @param  list<DriverPosition>  $positions  closest first
     * @return list<array{position: DriverPosition, cost: float, eta: EtaEstimate, terms: array<string, mixed>}>
     */
    public function rank(Ride $ride, array $positions): array
    {
        if ($positions === []) {
            return [];
        }

        $w = config('ehail_dispatch.weights');
        $ids = array_map(fn (DriverPosition $p) => $p->userId, $positions);
        $now = now();

        $availability = DriverAvailability::whereIn('user_id', $ids)->get(['user_id', 'online_since', 'last_trip_ended_at'])->keyBy('user_id');

        $tripsToday = Ride::whereIn('driver_id', $ids)
            ->where('status', RideStatus::Completed->value)
            ->where('completed_at', '>=', $now->copy()->startOfDay())
            ->groupBy('driver_id')->selectRaw('driver_id, count(*) as trips')->pluck('trips', 'driver_id');

        $cooldown = config('ehail_dispatch.decline_cooldown');
        $misses = RideOffer::whereIn('driver_id', $ids)
            ->whereIn('status', [RideOffer::DECLINED, RideOffer::EXPIRED])
            ->where('responded_at', '>=', $now->copy()->subMinutes((int) $cooldown['window_minutes']))
            ->groupBy('driver_id')->selectRaw('driver_id, count(*) as misses, max(responded_at) as last_miss')
            ->get()->keyBy('driver_id');

        $tripCounts = array_map(fn ($id) => (int) ($tripsToday[$id] ?? 0), $ids);
        sort($tripCounts);
        $median = $tripCounts[intdiv(count($tripCounts), 2)];

        $routerBudgetMs = (int) config('ehail_dispatch.eta.router_budget_ms');
        $routerMax = (int) config('ehail_dispatch.eta.router_max_candidates');
        $started = hrtime(true);
        $ranked = [];

        foreach ($positions as $index => $position) {
            $withinBudget = $index < $routerMax && (hrtime(true) - $started) / 1e6 < $routerBudgetMs;
            $eta = $this->estimate($withinBudget ? $this->eta : $this->fallbackEta, $position, $ride);

            $row = $availability[$position->userId] ?? null;
            $idleFrom = collect([$row?->last_trip_ended_at, $row?->online_since])->filter()->max();
            $idle = $idleFrom === null ? 0 : max(0, (int) $idleFrom->diffInSeconds($now, true));
            $idleCredit = (float) $w['idle_weight'] * min($idle, (int) $w['idle_cap_s']);

            $trips = (int) ($tripsToday[$position->userId] ?? 0);
            $balance = max(-(int) $w['trip_balance_cap_s'], min((int) $w['trip_balance_cap_s'], (int) $w['trip_balance_s_per_trip'] * ($trips - $median)));

            $miss = $misses[$position->userId] ?? null;
            $inCooldown = $miss !== null
                && (int) $miss->misses >= (int) $cooldown['threshold']
                && Carbon::parse($miss->last_miss)->gte($now->copy()->subMinutes((int) $cooldown['cooldown_minutes']));
            $cooldownS = $inCooldown ? (int) $w['cooldown_s'] : 0;

            $cost = round((float) $w['eta'] * $eta->seconds - $idleCredit + $balance + $cooldownS, 2);

            $ranked[] = [
                'position' => $position,
                'cost' => $cost,
                'eta' => $eta,
                'idle' => $idle,
                'trips' => $trips,
                'terms' => [
                    'eta_s' => $eta->seconds,
                    'eta_method' => $eta->method,
                    'pickup_distance_m' => $eta->distanceM,
                    'straight_line_m' => $position->straightLineM,
                    'idle_s' => $idle,
                    'idle_credit_s' => round($idleCredit, 2),
                    'trips_today' => $trips,
                    'median_trips_today' => $median,
                    'trip_balance_s' => $balance,
                    'recent_misses' => $miss === null ? 0 : (int) $miss->misses,
                    'cooldown_s' => $cooldownS,
                    'cost' => $cost,
                    'weights' => $w,
                ],
            ];
        }

        usort($ranked, fn ($a, $b) => [$a['cost'], -$a['idle'], $a['trips'], $a['position']->userId]
            <=> [$b['cost'], -$b['idle'], $b['trips'], $b['position']->userId]);

        foreach ($ranked as $rank => &$entry) {
            $entry['terms']['rank'] = $rank + 1;
            $entry['terms']['candidates'] = count($ranked);
        }

        return $ranked;
    }

    private function estimate(PickupEtaEstimator $estimator, DriverPosition $position, Ride $ride): EtaEstimate
    {
        try {
            return $estimator->estimate($position->lat, $position->lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng);
        } catch (\Throwable) {
            // A routing provider failing must not stop dispatch; rank by the bounded estimate.
            return $this->fallbackEta->estimate($position->lat, $position->lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng);
        }
    }
}
