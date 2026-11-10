<?php

namespace App\Domain\Ops\Live;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Support\Durations;
use App\Domain\Safety\Models\SafetyIncident;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The live board payload (page and polling feed): open rides, online drivers, queues and counts, all read from
 * the dispatch tables as they are now. Driver positions are included only for roles with drivers.positions.
 */
class LiveBoard
{
    public function __construct(private readonly StuckRides $stuck, private readonly OpsAccess $access) {}

    /** @return array<string, mixed> */
    public function payload(User $viewer): array
    {
        $now = now();
        $positionsAllowed = $this->access->can($viewer, OpsPermission::DRIVERS_POSITIONS);
        $staleSeconds = (int) config('ehail_dispatch.heartbeat_stale_seconds', 45);

        $open = Ride::query()
            ->whereIn('status', ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress', 'needs_review'])
            ->with(['passenger:id,name', 'driver:id,name'])
            ->orderBy('id')
            ->limit(1000)
            ->get();

        $availability = DriverAvailability::query()
            ->where('state', '!=', DriverAvailability::OFFLINE)
            ->with('user:id,name')
            ->orderBy('user_id')
            ->limit(2000)
            ->get();

        $isStale = fn (DriverAvailability $row) => $row->last_heartbeat_at === null || $row->last_heartbeat_at->lt($now->copy()->subSeconds($staleSeconds));

        $searching = $open->filter(fn (Ride $r) => in_array($r->status, ['requested', 'searching'], true))->sortBy('id')->values();
        $review = $open->where('status', 'needs_review')->sortBy('needs_review_at')->values();
        $stuck = $this->stuck->find();

        $showIncidents = $this->access->can($viewer, OpsPermission::SAFETY_VIEW);
        $activeIncidents = $showIncidents ? SafetyIncident::query()->whereIn('status', ['open', 'acknowledged', 'escalated'])->count() : null;

        return [
            'generated_at' => $now->toIso8601String(),
            'positions_allowed' => $positionsAllowed,
            'counts' => array_filter([
                'searching' => $searching->count(),
                'on_trip' => $open->filter(fn (Ride $r) => RideStatus::fromStored($r->status)?->holdsDriver())->count(),
                'needs_review' => $review->count(),
                'stuck' => $stuck->count(),
                'drivers_online' => $availability->filter(fn ($row) => $row->state === DriverAvailability::ONLINE && ! $isStale($row))->count(),
                'drivers_on_trip' => $availability->where('state', DriverAvailability::ON_TRIP)->count(),
                'drivers_paused' => $availability->where('state', DriverAvailability::PAUSED)->count(),
                'drivers_stale' => $availability->filter(fn ($row) => $row->state !== DriverAvailability::ON_TRIP && $isStale($row))->count(),
                'incidents' => $activeIncidents,
            ], fn ($value) => $value !== null),
            'drivers' => $positionsAllowed ? $availability->map(fn (DriverAvailability $row) => [
                'id' => $row->user_id,
                'label' => $row->user?->name ?? 'Driver '.$row->user_id,
                'state' => $row->state,
                'state_label' => match ($row->state) {
                    DriverAvailability::ON_TRIP => 'on a trip',
                    DriverAvailability::PAUSED => 'paused',
                    default => 'online',
                },
                'lat' => $row->last_lat,
                'lng' => $row->last_lng,
                'age_s' => $row->last_location_at ? (int) $row->last_location_at->diffInSeconds($now, true) : null,
                'stale' => $isStale($row),
                'ride_id' => $row->current_ride_id,
                'href' => route('ops.drivers.show', $row->user_id),
            ])->values()->all() : [],
            'rides' => $open->map(fn (Ride $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'status_label' => RideStatus::fromStored($r->status)?->label() ?? $r->status,
                'pickup' => $r->pickup_lat !== null ? ['lat' => (float) $r->pickup_lat, 'lng' => (float) $r->pickup_lng] : null,
                'href' => route('ops.rides.show', $r->id),
            ])->values()->all(),
            'queues' => [
                'searching' => $this->items($searching->take(12), fn (Ride $r) => [
                    'meta' => trim(($r->passenger?->name ?? 'Passenger').' · '.$r->offers_sent.' '.($r->offers_sent === 1 ? 'offer' : 'offers').' sent'.($r->reassignment_count ? ' · handed back '.$r->reassignment_count.'x' : '')),
                    'since' => $this->stuck->enteredStateAt($r),
                    'warn' => (int) config('ehail.ride_expiry_minutes', 10) * 30,
                    'label' => 'searching',
                ]),
                'needs_review' => $this->items($review->take(12), fn (Ride $r) => [
                    'meta' => $r->needs_review_reason ? str_replace('_', ' ', (string) $r->needs_review_reason) : 'Flagged for review',
                    'since' => $r->needs_review_at,
                    'warn' => 1,
                    'label' => 'waiting',
                ]),
                'stuck' => $this->items($stuck->take(12), fn (Ride $r) => [
                    'meta' => (RideStatus::fromStored($r->status)?->label() ?? $r->status).' · limit '.$this->stuck->limitMinutes((string) $r->status).' min',
                    'since' => $this->stuck->enteredStateAt($r),
                    'warn' => 1,
                    'label' => 'in this state',
                ]),
            ],
        ];
    }

    /**
     * @param  Collection<int, Ride>  $rides
     * @param  callable(Ride): array{meta: string, since: mixed, warn: int, label: string}  $describe
     * @return list<array<string, mixed>>
     */
    private function items(Collection $rides, callable $describe): array
    {
        return $rides->map(function (Ride $ride) use ($describe) {
            $d = $describe($ride);
            $elapsed = $d['since'] ? (int) $d['since']->diffInSeconds(now(), true) : null;

            return [
                'id' => $ride->id,
                'title' => 'Ride '.$ride->id.': '.str($ride->pickup_address ?? 'Pickup not recorded')->limit(40).' to '.str($ride->dropoff_address ?? 'drop-off not recorded')->limit(40),
                'meta' => $d['meta'],
                'href' => route('ops.rides.show', $ride->id),
                'since' => $d['since']?->toIso8601String(),
                'timer' => $elapsed !== null ? $d['label'].' '.Durations::short($elapsed) : '',
                'tone' => $elapsed !== null && $elapsed >= $d['warn'] ? ($d['warn'] === 1 ? 'stop' : 'caution') : 'calm',
                'warn' => $d['warn'],
                'label' => $d['label'],
            ];
        })->values()->all();
    }
}
