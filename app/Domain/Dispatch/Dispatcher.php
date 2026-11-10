<?php

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Contracts\ServiceAreaCheck;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\FeedVersion;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\RideTimeline;
use App\Domain\Rides\TransitionOptions;
use App\Domain\Rides\TripPin;
use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Ehail\InvalidRideTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intelligent dispatch without a daemon (D-2): sequential offers to one
 * driver at a time, advanced on demand by tick(), which passenger and driver
 * polls and the per-minute scheduler call.
 *
 * Concurrency (docs/R46_DISPATCH.md "Concurrency"):
 *  - a tick works on a ride only while it holds the ride's lease (a
 *    compare-and-swap UPDATE on rides.dispatch_lease_until, atomic on every
 *    engine, including SQLite where lockForUpdate is a no-op);
 *  - an offer is created inside a transaction that re-reads the ride under
 *    lockForUpdate; pending_ride_id / pending_driver_id are unique, so a ride
 *    never has two offers in flight and a driver never sees two at once;
 *  - acceptance is one transaction: a CAS UPDATE of the offer (still
 *    pending, still unexpired, this driver) and then the state machine
 *    transition; if either fails nothing is written.
 */
class Dispatcher
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly RideTimeline $timeline,
        private readonly DriverLocator $locator,
        private readonly CandidateScorer $scorer,
        private readonly CategoryEligibility $categories,
        private readonly ServiceAreaCheck $serviceAreas,
        private readonly TripPin $pin,
    ) {}

    /**
     * A new (or newly due scheduled) ride starts searching and gets its first offer.
     */
    public function request(Ride $ride): Ride
    {
        $ride = $this->machine->transition($ride, RideStatus::Searching, RideActor::System, null,
            new TransitionOptions(key: 'search:start:'.$ride->reassignment_count, reason: 'dispatch_started'));

        $this->tickRide($ride);

        return $ride->fresh();
    }

    /**
     * Advance dispatch: one ride (a passenger's or driver's poll), or the due
     * rides in priority order (the scheduler, and at most every few seconds
     * from any poll so a waiting ride moves even when nobody watches it).
     */
    public function tick(?Ride $ride = null): void
    {
        if ($ride !== null) {
            $this->tickRide($ride);

            return;
        }

        $every = max(1, (int) config('ehail_dispatch.poll.global_tick_every_seconds'));

        if (! Cache::add('ehail:dispatch:global-tick', 1, $every)) {
            return;
        }

        $this->tickDue((int) config('ehail_dispatch.poll.global_tick_batch'));
    }

    /**
     * @return int rides ticked
     */
    public function tickDue(int $limit): int
    {
        $now = now();

        $ids = Ride::whereIn('status', [RideStatus::Requested->value, RideStatus::Searching->value])
            ->where(fn ($q) => $q->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('pending_offer_expires_at')->orWhere('pending_offer_expires_at', '<=', $now))
            ->orderByDesc('dispatch_priority')
            ->orderByRaw('coalesce(search_started_at, requested_at) asc')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            if ($ride = Ride::find($id)) {
                $this->tickRide($ride);
            }
        }

        return $ids->count();
    }

    /**
     * One dispatch step for one ride under its lease. Returns the pending offer, if any.
     */
    public function tickRide(Ride $ride): ?RideOffer
    {
        $owner = (string) Str::uuid();
        $leased = Ride::whereKey($ride->id)
            ->whereIn('status', [RideStatus::Requested->value, RideStatus::Searching->value])
            ->where(fn ($q) => $q->whereNull('dispatch_lease_until')->orWhere('dispatch_lease_until', '<', now()))
            ->update([
                'dispatch_lease_until' => now()->addSeconds((int) config('ehail_dispatch.lease_seconds')),
                'dispatch_lease_owner' => $owner,
            ]);

        if ($leased !== 1) {
            return RideOffer::where('pending_ride_id', $ride->id)->first();
        }

        try {
            return $this->step($ride->id);
        } finally {
            Ride::whereKey($ride->id)->where('dispatch_lease_owner', $owner)
                ->update(['dispatch_lease_until' => null, 'dispatch_lease_owner' => null]);
        }
    }

    private function step(int $rideId): ?RideOffer
    {
        $ride = Ride::findOrFail($rideId);

        if ($ride->statusEnum() === RideStatus::Requested) {
            $ride = $this->machine->transition($ride, RideStatus::Searching, RideActor::System, null,
                new TransitionOptions(key: 'search:start:'.$ride->reassignment_count, reason: 'dispatch_started'));
        }

        $this->expireLapsedOffer($ride);

        if ($live = RideOffer::where('pending_ride_id', $ride->id)->first()) {
            return $live;
        }

        if ($this->windowElapsed($ride)) {
            $this->expire($ride);

            return null;
        }

        if ($ride->pickup_lat === null || $ride->pickup_lng === null) {
            // No coordinates (a pre-R-46 address-only request): nobody can be
            // ranked by distance; the ride waits on the open board.
            $this->scheduleRetry($ride);

            return null;
        }

        $radii = config('ehail_dispatch.search_radii_m');
        $exclude = $this->excludedDrivers($ride);

        for ($round = min($ride->search_round, count($radii) - 1); $round < count($radii); $round++) {
            $candidates = $this->eligible($ride, $this->locator->nearbyDrivers(
                (float) $ride->pickup_lat, (float) $ride->pickup_lng, (int) $radii[$round],
                (int) config('ehail_dispatch.candidate_limit'), $exclude,
            ));

            foreach ($this->scorer->rank($ride, $candidates) as $choice) {
                $offer = $this->createOffer($ride->id, $choice, $round, (int) $radii[$round]);

                if ($offer !== null) {
                    return $offer;
                }
            }
        }

        $this->scheduleRetry($ride, count($radii) - 1);

        return null;
    }

    /**
     * Driver answer to an offer. Accept: atomic, see the class comment.
     * Decline: recorded (with a reason), then the next driver is offered at once.
     */
    public function offerResponse(RideOffer $offer, User $driver, string $answer, ?string $reason = null): Ride
    {
        if ($offer->driver_id !== $driver->id) {
            throw new DispatchRefused('not_your_offer', 'That offer was not made to you.', 403);
        }

        return $answer === 'accept' ? $this->accept($offer, $driver) : $this->decline($offer, $driver, $reason);
    }

    public function markSeen(RideOffer $offer, User $driver): void
    {
        RideOffer::whereKey($offer->id)->where('driver_id', $driver->id)->whereNull('seen_at')->update(['seen_at' => now(), 'updated_at' => now(), 'version' => FeedVersion::now()]);
    }

    /**
     * Open board ("trip radar" fallback): the driver claims a ride that has
     * searched past ehail_dispatch.open_board_after_seconds. Any pending
     * offer for it is withdrawn in the same transaction.
     */
    public function claimFromBoard(Ride $ride, User $driver): Ride
    {
        if (! $this->onBoard($ride) && ! RideOffer::where('pending_ride_id', $ride->id)->where('driver_id', $driver->id)->exists()) {
            throw new InvalidRideTransition('That ride is not open to claim yet.');
        }

        return $this->machine->transition($ride, RideStatus::DriverAssigned, RideActor::Driver, $driver,
            new TransitionOptions(key: "board:{$ride->id}:{$driver->id}:{$ride->reassignment_count}", reason: 'open_board_claim'));
    }

    public function onBoard(Ride $ride): bool
    {
        $after = (int) config('ehail_dispatch.open_board_after_seconds');

        if ($after < 0 || ! in_array($ride->statusEnum(), [RideStatus::Requested, RideStatus::Searching], true) || $ride->driver_id !== null) {
            return false;
        }

        $since = $ride->search_started_at ?? $ride->requested_at ?? $ride->created_at;

        return $after === 0 || $since->lte(now()->subSeconds($after));
    }

    /**
     * Rides on the open board this driver's active vehicle may serve, newest first.
     */
    public function boardFor(Vehicle $vehicle): Collection
    {
        $after = (int) config('ehail_dispatch.open_board_after_seconds');

        if ($after < 0) {
            return collect();
        }

        $cutoff = now()->subSeconds($after);

        // The same eligibility the claim applies: an Economy ride a standard car may claim is listed for it (D-1610).
        return Ride::select(['id', 'status', 'pickup_address', 'dropoff_address', 'vehicle_type', 'category_code', 'estimated_fare', 'quoted_total_minor', 'currency', 'created_at'])
            ->whereIn('status', [RideStatus::Requested->value, RideStatus::Searching->value])
            ->whereNull('driver_id')
            ->when($after > 0, fn ($q) => $q->whereRaw('coalesce(search_started_at, requested_at, created_at) <= ?', [$cutoff]))
            ->latest()->limit(200)->get()
            ->filter(fn (Ride $ride) => $this->vehicleMayClaim($vehicle, $ride))
            ->values();
    }

    /**
     * Who may claim a ride from the board (listing and the accept policy agree with the claim's guard): an R-46
     * ride by its category's vehicle rules; a ride from before categories by the configured vehicle tiers.
     */
    public function vehicleMayClaim(Vehicle $vehicle, Ride $ride): bool
    {
        return $ride->category_code !== null
            ? $this->categories->vehicleCanServe($vehicle, $ride->category_code)
            : $vehicle->canServe((string) $ride->vehicle_type);
    }

    /**
     * The assigned driver moves the ride one stage on. Starting the trip
     * checks the PIN first (wrong attempts are counted even though the
     * transition is refused).
     */
    public function advance(Ride $ride, User $driver, RideStatus $to, ?string $pin = null): Ride
    {
        if ($ride->statusEnum()->nextStage() !== $to) {
            throw InvalidRideTransition::between($ride->statusEnum(), $to);
        }

        if ($ride->driver_id !== $driver->id) {
            throw new InvalidRideTransition('That ride is not assigned to you.');
        }

        $verified = false;

        if ($to === RideStatus::InProgress && $this->pin->required($ride)) {
            $verified = $this->pin->verify($ride, $driver, $pin);
        }

        return $this->machine->transition($ride, $to, RideActor::Driver, $driver,
            new TransitionOptions(key: "advance:{$to->value}:{$ride->reassignment_count}", pinVerified: $verified));
    }

    /**
     * The assigned driver backs out: back to searching with priority while
     * the reassignment policy allows, otherwise cancelled_by_driver.
     */
    public function driverCancel(Ride $ride, User $driver, CancelReason $reason, ?string $note = null): Ride
    {
        $requeue = $ride->reassignment_count < (int) config('ehail.max_reassignments');

        $ride = $this->machine->transition($ride, $requeue ? RideStatus::Searching : RideStatus::CancelledByDriver,
            RideActor::Driver, $driver, new TransitionOptions(reason: $reason->value, note: $note));

        if ($requeue) {
            $this->tickRide($ride);
        }

        return $ride->fresh();
    }

    public function recordNoShow(Ride $ride, User $driver): Ride
    {
        return $this->machine->transition($ride, RideStatus::NoShow, RideActor::Driver, $driver,
            new TransitionOptions(key: 'no_show', reason: 'passenger_no_show'));
    }

    private function accept(RideOffer $offer, User $driver): Ride
    {
        return DB::transaction(function () use ($offer, $driver) {
            $ride = Ride::whereKey($offer->ride_id)->lockForUpdate()->firstOrFail();

            $claimed = RideOffer::whereKey($offer->id)
                ->where('driver_id', $driver->id)
                ->where('status', RideOffer::PENDING)
                ->where('expires_at', '>', now())
                ->update([
                    'status' => RideOffer::ACCEPTED, 'pending_ride_id' => null, 'pending_driver_id' => null,
                    'responded_at' => now(), 'updated_at' => now(), 'version' => FeedVersion::now(),
                ]);

            if ($claimed !== 1) {
                $current = $offer->fresh();

                // A repeated tap on an offer this driver already won is not an error.
                if ($current?->status === RideOffer::ACCEPTED && $ride->driver_id === $driver->id) {
                    return $ride;
                }

                throw new DispatchRefused('offer_unavailable', 'This offer is no longer available.', 409);
            }

            return $this->machine->transition($ride, RideStatus::DriverAssigned, RideActor::Driver, $driver, new TransitionOptions(
                key: "offer:{$offer->id}:accept",
                reason: 'offer_accepted',
                vehicle: $offer->vehicle_id ? Vehicle::find($offer->vehicle_id) : null,
                context: ['offer_id' => $offer->id, 'round' => $offer->round],
            ));
        }, 3);
    }

    private function decline(RideOffer $offer, User $driver, ?string $reason): Ride
    {
        $declined = DB::transaction(function () use ($offer, $driver, $reason) {
            $ride = Ride::whereKey($offer->ride_id)->lockForUpdate()->firstOrFail();

            $n = RideOffer::whereKey($offer->id)->where('driver_id', $driver->id)->where('status', RideOffer::PENDING)
                ->update([
                    'status' => RideOffer::DECLINED, 'pending_ride_id' => null, 'pending_driver_id' => null,
                    'responded_at' => now(), 'reason' => $reason ?? 'declined', 'updated_at' => now(), 'version' => FeedVersion::now(),
                ]);

            if ($n === 1) {
                // Dispatch bookkeeping is written without bumping the feed version: nothing the passenger sees changed.
                Ride::whereKey($ride->id)->update(['pending_offer_expires_at' => null, 'next_dispatch_at' => now()]);
                $this->timeline->append($ride, 'offer_declined', RideActor::Driver, $driver->id, $reason ?? 'declined', ['offer_id' => $offer->id]);
            }

            return $ride;
        }, 3);

        $this->tickRide($declined);

        return $declined->fresh();
    }

    private function expireLapsedOffer(Ride $ride): void
    {
        $lapsed = RideOffer::where('pending_ride_id', $ride->id)->where('expires_at', '<=', now())->first();

        if ($lapsed === null) {
            return;
        }

        DB::transaction(function () use ($ride, $lapsed) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            $n = RideOffer::whereKey($lapsed->id)->where('status', RideOffer::PENDING)->where('expires_at', '<=', now())->update([
                'status' => RideOffer::EXPIRED, 'pending_ride_id' => null, 'pending_driver_id' => null,
                'responded_at' => now(), 'reason' => 'timeout', 'updated_at' => now(), 'version' => FeedVersion::now(),
            ]);

            if ($n === 1) {
                Ride::whereKey($locked->id)->update(['pending_offer_expires_at' => null, 'next_dispatch_at' => now()]);
                $this->timeline->append($locked, 'offer_expired', RideActor::System, null, 'timeout', [
                    'offer_id' => $lapsed->id, 'driver_id' => $lapsed->driver_id,
                ]);
            }
        }, 3);
    }

    /**
     * @param  array{position: DriverPosition, cost: float, eta: EtaEstimate, terms: array<string, mixed>}  $choice
     */
    private function createOffer(int $rideId, array $choice, int $round, int $radius): ?RideOffer
    {
        try {
            return DB::transaction(function () use ($rideId, $choice, $round, $radius) {
                $ride = Ride::whereKey($rideId)->lockForUpdate()->firstOrFail();

                if ($ride->statusEnum() !== RideStatus::Searching || $ride->driver_id !== null) {
                    return null;
                }

                $driverId = $choice['position']->userId;

                // Re-check the driver under the transaction: still online, still free.
                $available = DriverAvailability::where('user_id', $driverId)
                    ->where('state', DriverAvailability::ONLINE)->whereNull('current_ride_id')->exists();

                if (! $available || Ride::where('active_driver_id', $driverId)->exists()) {
                    return null;
                }

                $timeout = (int) config('ehail_dispatch.offer_timeout_seconds');
                $offer = RideOffer::create([
                    'ride_id' => $ride->id,
                    'driver_id' => $driverId,
                    'vehicle_id' => $choice['position']->vehicleId,
                    'status' => RideOffer::PENDING,
                    'pending_ride_id' => $ride->id,
                    'pending_driver_id' => $driverId,
                    'round' => $round,
                    'radius_m' => $radius,
                    'pickup_distance_m' => $choice['eta']->distanceM,
                    'pickup_eta_s' => $choice['eta']->seconds,
                    'eta_method' => $choice['eta']->method,
                    'score' => $choice['cost'],
                    'score_terms' => $choice['terms'] + ['service_area' => $this->serviceAreas->covers((float) $ride->pickup_lat, (float) $ride->pickup_lng)],
                    'sent_at' => now(),
                    'expires_at' => now()->addSeconds($timeout),
                ]);

                Ride::whereKey($ride->id)->update([
                    'pending_offer_expires_at' => $offer->expires_at,
                    'next_dispatch_at' => $offer->expires_at,
                    'offers_sent' => $ride->offers_sent + 1,
                    'search_round' => $round,
                ]);

                $this->timeline->append($ride, 'offer_sent', RideActor::System, null, 'sequential_offer', [
                    'offer_id' => $offer->id, 'driver_id' => $driverId, 'round' => $round, 'radius_m' => $radius,
                    'eta_s' => $choice['eta']->seconds, 'eta_method' => $choice['eta']->method, 'cost' => $choice['cost'],
                ]);

                return $offer;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The driver received another ride's offer a moment ago (or another
            // tick placed this ride's offer): try the next candidate.
            return null;
        }
    }

    /**
     * Drivers not to offer this ride again: anyone who declined it or backed
     * out of it, and anyone whose offer for it lapsed in the last minute.
     *
     * @return list<int>
     */
    private function excludedDrivers(Ride $ride): array
    {
        return RideOffer::where('ride_id', $ride->id)
            ->where(fn ($q) => $q->whereIn('status', [RideOffer::DECLINED, RideOffer::ACCEPTED, RideOffer::PENDING])
                ->orWhere('responded_at', '>=', now()->subSeconds(60)))
            ->pluck('driver_id')->unique()->values()->all();
    }

    /**
     * @param  list<DriverPosition>  $positions
     * @return list<DriverPosition>
     */
    private function eligible(Ride $ride, array $positions): array
    {
        if ($positions === []) {
            return [];
        }

        if ($this->serviceAreas->covers((float) $ride->pickup_lat, (float) $ride->pickup_lng) === false) {
            return [];
        }

        $category = $ride->category_code ?? $ride->vehicle_type;
        $vehicleIds = array_filter(array_map(fn (DriverPosition $p) => $p->vehicleId, $positions));
        $vehicles = Vehicle::whereIn('id', $vehicleIds)->where('is_active', true)->get()->keyBy('id');
        $approved = DriverProfile::withoutGlobalScope('user')
            ->whereIn('user_id', array_map(fn (DriverPosition $p) => $p->userId, $positions))
            ->where('status', 'approved')->pluck('id', 'user_id');

        return array_values(array_filter($positions, function (DriverPosition $p) use ($vehicles, $approved, $category, $ride) {
            $vehicle = $p->vehicleId ? ($vehicles[$p->vehicleId] ?? null) : null;

            return $p->userId !== $ride->passenger_id
                && isset($approved[$p->userId])
                && $vehicle !== null
                && $vehicle->driver_profile_id === $approved[$p->userId]
                && $this->categories->vehicleCanServe($vehicle, $category);
        }));
    }

    private function windowElapsed(Ride $ride): bool
    {
        $since = $ride->search_started_at ?? $ride->requested_at ?? $ride->created_at;

        return $since->lt(now()->subMinutes((int) config('ehail.ride_expiry_minutes')));
    }

    private function expire(Ride $ride): void
    {
        try {
            $this->machine->transition($ride, RideStatus::Expired, RideActor::System, null,
                new TransitionOptions(key: 'expire:'.$ride->reassignment_count, reason: 'no_driver_found', context: ['offers_sent' => $ride->offers_sent]));
        } catch (InvalidRideTransition) {
            // Accepted or cancelled between the read and the lock.
        }
    }

    private function scheduleRetry(Ride $ride, ?int $round = null): void
    {
        Ride::whereKey($ride->id)->update(array_filter([
            'next_dispatch_at' => now()->addSeconds((int) config('ehail_dispatch.retry_seconds')),
            'search_round' => $round,
        ], fn ($v) => $v !== null));
    }
}
