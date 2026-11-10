<?php

namespace App\Domain\Safety;

use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;

/**
 * Flags accounts that cancel many rides after a driver was assigned, for an operator to look at.
 * Never suspends, never lowers anything, never acts on ratings: it only opens a user_reports row
 * (category cancellation_pattern) once per account per window.
 *
 * Driver cancellations are read from the audit log ("ride.driver_cancelled", written by the
 * current lifecycle when a driver drops an accepted ride, which then re-queues) and from rides
 * whose status is cancelled_by_driver under the R-46 lifecycle. Passenger cancellations are rides
 * the passenger cancelled (cancelled_by = passenger_id, or cancelled_by_passenger) after a driver
 * accepted.
 */
class CancellationPatternMonitor
{
    public function __construct(private readonly UserReportService $reports) {}

    public function scan(): int
    {
        $days = (int) config('ehail_safety.cancellations.window_days', 7);
        $since = now()->subDays($days);
        $bucket = now()->format('o-\WW');
        $flags = 0;

        $passengerCounts = Ride::query()
            ->whereIn('status', ['cancelled', 'cancelled_by_passenger'])
            ->where(fn ($q) => $q->whereColumn('cancelled_by', 'passenger_id')->orWhere('status', 'cancelled_by_passenger'))
            ->whereNotNull('accepted_at')
            ->where('cancelled_at', '>=', $since)
            ->selectRaw('passenger_id as user_id, count(*) as total')
            ->groupBy('passenger_id')
            ->havingRaw('count(*) >= ?', [(int) config('ehail_safety.cancellations.passenger_threshold', 5)])
            ->get();

        foreach ($passengerCounts as $row) {
            $flags += $this->flag((int) $row->user_id, 'passenger', (int) $row->total, $days, $bucket);
        }

        $driverCounts = AuditLog::query()
            ->where('action', 'ride.driver_cancelled')
            ->where('created_at', '>=', $since)
            ->whereNotNull('actor_user_id')
            ->selectRaw('actor_user_id as user_id, count(*) as total')
            ->groupBy('actor_user_id')
            ->pluck('total', 'user_id');

        $driverCancelled = Ride::query()
            ->where(fn ($q) => $q->where('status', 'cancelled_by_driver')->orWhere(fn ($q) => $q->where('status', 'cancelled')->whereColumn('cancelled_by', 'driver_id')))
            ->where('cancelled_at', '>=', $since)
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id as user_id, count(*) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'user_id');

        $threshold = (int) config('ehail_safety.cancellations.driver_threshold', 5);

        foreach ($driverCounts->keys()->merge($driverCancelled->keys())->unique() as $userId) {
            $total = (int) ($driverCounts[$userId] ?? 0) + (int) ($driverCancelled[$userId] ?? 0);

            if ($total >= $threshold) {
                $flags += $this->flag((int) $userId, 'driver', $total, $days, $bucket);
            }
        }

        return $flags;
    }

    private function flag(int $userId, string $role, int $total, int $days, string $bucket): int
    {
        $user = User::find($userId);

        if ($user === null) {
            return 0;
        }

        $report = $this->reports->flag(
            $user,
            $role,
            'cancellation_pattern',
            "{$total} cancellations after a driver was assigned in the last {$days} days (as {$role}). For review only; no action was taken automatically.",
            ['cancellations' => $total, 'window_days' => $days],
            "cancel:{$role}:{$userId}:{$bucket}",
        );

        return $report === null ? 0 : 1;
    }
}
