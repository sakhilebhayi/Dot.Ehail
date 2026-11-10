<?php

namespace App\Domain\Fleet;

use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Fleet\Models\DriverOnlineSession;
use App\Domain\Rides\Models\RideEvent;
use App\Domain\Safety\TrustSignals;
use App\Enums\RideStatus;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Factual driver and fleet performance for a period, from records only:
 *   trips            rides the driver completed in the period (completed_at)
 *   cancellations    times the driver cancelled or handed back a ride they held (ride_events by the driver)
 *   completion rate  trips / (trips + cancellations); null when both are zero
 *   acceptance rate  offers accepted / offers accepted, declined or let lapse (ride_offers); null without offers
 *   rating band      safety's band over all counted ratings ("new" below the minimum count), never a decimal
 *   time online      measured sessions overlapping the period, since measurement began; null before that
 */
final class FleetPerformance
{
    public function __construct(private readonly FleetAccess $access) {}

    /**
     * @return array{rows: list<array<string, mixed>>, fleet: array<string, mixed>, measured_since: ?Carbon}
     */
    public function forFleet(User $viewer, Fleet $fleet, CarbonInterface $from, CarbonInterface $to): array
    {
        $drivers = $this->access->drivers($viewer, $fleet);
        $ids = $drivers->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $trips = Ride::query()->whereIn('driver_id', $ids)->where('status', RideStatus::Completed->value)
            ->whereBetween('completed_at', [$from, $to])->selectRaw('driver_id, count(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');

        $cancellations = RideEvent::query()->whereIn('actor_id', $ids)->where('actor_type', 'driver')
            ->whereIn('to_status', [RideStatus::CancelledByDriver->value, RideStatus::Searching->value])
            ->whereIn('from_status', RideStatus::values(RideStatus::driverHoldingStates()))
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('actor_id, count(*) as n')->groupBy('actor_id')->pluck('n', 'actor_id');

        $offers = RideOffer::query()->whereIn('driver_id', $ids)->whereBetween('sent_at', [$from, $to])
            ->whereIn('status', [RideOffer::ACCEPTED, RideOffer::DECLINED, RideOffer::EXPIRED])
            ->selectRaw('driver_id, status, count(*) as n')->groupBy('driver_id', 'status')->get()
            ->groupBy('driver_id');

        $online = $this->onlineSeconds($ids, $from, $to);
        $measuredSince = DriverOnlineSession::query()->min('started_at');

        $rows = $drivers->map(function ($profile) use ($trips, $cancellations, $offers, $online) {
            $id = (int) $profile->user_id;
            $done = (int) ($trips[$id] ?? 0);
            $cancelled = (int) ($cancellations[$id] ?? 0);
            /** @var Collection<int, object{status: string, n: int}> $mine */
            $mine = $offers->get($id, collect());
            $answered = (int) $mine->sum('n');
            $accepted = (int) ($mine->firstWhere('status', RideOffer::ACCEPTED)?->n ?? 0);
            $stats = TrustSignals::ratingStats($id, 'driver');

            return [
                'driver_id' => $id,
                'profile_id' => $profile->id,
                'name' => (string) ($profile->user?->name ?? 'Driver #'.$profile->id),
                'status' => $profile->status,
                'trips' => $done,
                'cancellations' => $cancelled,
                'completion_bp' => ($done + $cancelled) > 0 ? intdiv($done * 10000, $done + $cancelled) : null,
                'offers' => $answered,
                'acceptance_bp' => $answered > 0 ? intdiv($accepted * 10000, $answered) : null,
                'rating' => TrustSignals::band($stats['average'], $stats['count']),
                'online_seconds' => $online[$id] ?? null,
            ];
        })->values()->all();

        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $tripsTotal = $sum('trips');
        $cancelTotal = $sum('cancellations');

        return [
            'rows' => $rows,
            'fleet' => [
                'drivers' => count($rows),
                'trips' => $tripsTotal,
                'cancellations' => $cancelTotal,
                'completion_bp' => ($tripsTotal + $cancelTotal) > 0 ? intdiv($tripsTotal * 10000, $tripsTotal + $cancelTotal) : null,
                'online_seconds' => $measuredSince === null ? null : array_sum(array_map(fn ($r) => $r['online_seconds'] ?? 0, $rows)),
            ],
            'measured_since' => $measuredSince === null ? null : Carbon::parse($measuredSince),
        ];
    }

    /**
     * Seconds each driver spent out of the offline state inside [from, to], from measured sessions. Drivers with no
     * session are absent (unknown is not zero unless measurement had started; the caller decides how to show it).
     *
     * @param  list<int>  $driverIds
     * @return array<int, int>
     */
    public function onlineSeconds(array $driverIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $measuring = DriverOnlineSession::query()->exists();
        $totals = $measuring ? array_fill_keys($driverIds, 0) : [];

        DriverOnlineSession::query()->whereIn('user_id', $driverIds)
            ->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))
            ->get()
            ->each(function (DriverOnlineSession $s) use (&$totals, $from, $to) {
                $start = $s->started_at->max($from);
                $end = ($s->ended_at ?? now())->min($to)->min(now());
                $totals[$s->user_id] = ($totals[$s->user_id] ?? 0) + max(0, (int) $start->diffInSeconds($end, false));
            });

        return $totals;
    }

    /**
     * Driver cancellations of held rides and finished assignments in the window, for the unusual-cancellations alert.
     *
     * @param  list<int>  $driverIds
     * @return array<int, array{cancellations: int, trips: int}>
     */
    public function cancellationWindow(array $driverIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $cancellations = RideEvent::query()->whereIn('actor_id', $driverIds)->where('actor_type', 'driver')
            ->whereIn('to_status', [RideStatus::CancelledByDriver->value, RideStatus::Searching->value])
            ->whereIn('from_status', RideStatus::values(RideStatus::driverHoldingStates()))
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('actor_id, count(*) as n')->groupBy('actor_id')->pluck('n', 'actor_id');
        $trips = Ride::query()->whereIn('driver_id', $driverIds)->where('status', RideStatus::Completed->value)
            ->whereBetween('completed_at', [$from, $to])->selectRaw('driver_id, count(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');

        $out = [];

        foreach ($driverIds as $id) {
            $out[$id] = ['cancellations' => (int) ($cancellations[$id] ?? 0), 'trips' => (int) ($trips[$id] ?? 0)];
        }

        return $out;
    }
}
