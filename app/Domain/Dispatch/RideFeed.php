<?php

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEarningsEstimator;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\LatLng;
use App\Domain\Perf\PollPolicy;
use App\Domain\Rides\ScheduledRides;
use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Polling payloads (D-1): compact JSON with a monotonic version. A client
 * sends the version it holds; only the sections that changed since are
 * returned, or nothing (the controller answers 304).
 *
 * Versions are epoch milliseconds, strictly increasing per source (a ride's
 * version, an availability row's version, a position's recorded time, an
 * offer's last change), so max() over the sources is monotonic for a feed.
 *
 * Privacy (POPIA minimisation, architecture section 8):
 *  - passenger feed: only the passenger's own ride; the assigned driver's
 *    position only while the driver holds the ride (assigned, arriving,
 *    arrived, in progress), first name, rating, vehicle;
 *  - driver feed: the offer and ride the driver is part of; the passenger's
 *    first name, pickup point and a rating band. The passenger's live
 *    location is never collected, so it can never be sent.
 */
class RideFeed
{
    public function __construct(
        private readonly DriverLocator $locator,
        private readonly PickupEtaEstimator $eta,
        private readonly DriverEarningsEstimator $earnings,
        private readonly PollPolicy $poll,
    ) {}

    /**
     * @return array{version: int, body: array<string, mixed>|null}
     */
    public function forPassenger(Ride $ride, int $sinceVersion = 0): array
    {
        $status = $ride->statusEnum();
        $position = $status->holdsDriver() && $ride->driver_id !== null ? $this->locator->latest($ride->driver_id) : null;
        $positionVersion = $position ? max($position->version, (int) $position->recordedAt->getPreciseTimestamp(6)) : 0;
        $version = max((int) $ride->version, $positionVersion);

        if ($version <= $sinceVersion) {
            return ['version' => $version, 'body' => null];
        }

        $body = [
            'version' => $version,
            'server_time' => now()->toIso8601String(),
            'poll_after_s' => $this->passengerPollSeconds($status),
        ];

        if ((int) $ride->version > $sinceVersion) {
            $body['ride'] = $this->passengerRide($ride);
        }

        if ($status->holdsDriver()) {
            if ($position === null) {
                $body['driver_position'] = null;
                $body['connection'] = ['driver_position' => 'unknown'];
            } elseif ($positionVersion > $sinceVersion || isset($body['ride'])) {
                $body['driver_position'] = $this->positionFor($ride, $position);
                $body['connection'] = ['driver_position' => $body['driver_position']['stale'] ? 'stale' : 'live'];
            }
        }

        return ['version' => $version, 'body' => $body];
    }

    /**
     * @return array{version: int, body: array<string, mixed>|null, poll: int}
     */
    public function forDriver(User $driver, int $sinceVersion = 0, bool $saver = false): array
    {
        $availability = DriverAvailability::where('user_id', $driver->id)->first();
        $offer = RideOffer::where('pending_driver_id', $driver->id)->first();
        $ride = Ride::where('active_driver_id', $driver->id)->first();

        $version = max(
            (int) ($availability?->version ?? 0),
            (int) ($ride?->version ?? 0),
            // Any change to any of this driver's offers (sent, seen, expired, withdrawn) changes their view.
            (int) RideOffer::where('driver_id', $driver->id)->max('version'),
        );

        // Adaptive polling (PollPolicy, docs/R46_PERF.md): from what can happen next for this driver.
        $poll = $this->poll->forDriverState($availability?->state, $offer !== null, $ride !== null, $saver);

        if ($version <= $sinceVersion) {
            return ['version' => $version, 'body' => null, 'poll' => $poll];
        }

        return ['version' => $version, 'poll' => $poll, 'body' => [
            'version' => $version,
            'server_time' => now()->toIso8601String(),
            'poll_after_s' => $poll,
            'availability' => $availability === null ? ['state' => DriverAvailability::OFFLINE] : [
                'state' => $availability->state,
                'since' => $this->iso($availability->since),
                'vehicle_id' => $availability->vehicle_id,
                'last_heartbeat_at' => $this->iso($availability->last_heartbeat_at),
                'heartbeat_stale' => $availability->isStale(),
                'location_fresh' => $availability->hasFreshLocation(),
            ],
            'offer' => $offer === null ? null : $this->driverOffer($offer, $driver),
            'ride' => $ride === null ? null : $this->driverRide($ride),
        ]];
    }

    public function passengerPollSeconds(RideStatus $status): int
    {
        return $this->poll->forPassenger($status);
    }

    /**
     * @return array<string, mixed>
     */
    private function passengerRide(Ride $ride): array
    {
        $status = $ride->statusEnum();
        $vehicle = $ride->vehicle_id ? Vehicle::find($ride->vehicle_id, ['id', 'make', 'model', 'color', 'plate_number']) : null;
        $showDriver = $ride->driver_id !== null && ($status->holdsDriver() || $status === RideStatus::Completed);
        $windowMinutes = (int) config('ehail.ride_expiry_minutes');

        return [
            'id' => $ride->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'scheduled_for' => $this->iso($ride->scheduled_for),
            'schedule_phase' => ScheduledRides::phase($ride),
            'pickup' => $this->place($ride->pickup_address, $ride->pickup_lat, $ride->pickup_lng),
            'dropoff' => $this->place($ride->dropoff_address, $ride->dropoff_lat, $ride->dropoff_lng),
            'category' => $ride->category_code ?? $ride->vehicle_type,
            'fare' => [
                'quoted_total_minor' => $ride->quoted_total_minor,
                'final_total_minor' => $ride->final_fare_minor,
                'cancellation_fee_minor' => $ride->cancellation_fee_minor,
                'currency' => $ride->currency,
                'quote_id' => $ride->fare_quote_id,
            ],
            'requote' => $ride->requote_required_at === null ? null : [
                'total_minor' => $ride->requote_total_minor,
                'quote_id' => $ride->requote_quote_id,
                'since' => $this->iso($ride->requote_required_at),
            ],
            'payment_method' => $ride->payment_method_kind,
            'search' => $status->isAwaitingDriver() && $status !== RideStatus::Scheduled ? [
                'started_at' => $this->iso($ride->search_started_at ?? $ride->requested_at),
                'window_ends_at' => $this->iso(($ride->search_started_at ?? $ride->requested_at)?->copy()->addMinutes($windowMinutes)),
            ] : null,
            // Shown to the passenger only, and only until the trip starts.
            'pin' => in_array($status, [RideStatus::DriverAssigned, RideStatus::DriverArriving, RideStatus::DriverArrived], true) && $ride->pin_hash !== null
                ? $ride->pin_encrypted : null,
            'driver' => $showDriver ? [
                'first_name' => $this->firstName($ride->driver?->name),
                'rating' => $this->driverRating($ride->driver_id),
                'vehicle' => $vehicle ? [
                    'make' => $vehicle->make, 'model' => $vehicle->model, 'color' => $vehicle->color, 'plate' => $vehicle->plate_number,
                ] : null,
            ] : null,
            'timestamps' => array_filter([
                'requested_at' => $this->iso($ride->requested_at),
                'accepted_at' => $this->iso($ride->accepted_at),
                'driver_arriving_at' => $this->iso($ride->driver_arriving_at),
                'arrived_at' => $this->iso($ride->arrived_at),
                'started_at' => $this->iso($ride->started_at),
                'completed_at' => $this->iso($ride->completed_at),
                'cancelled_at' => $this->iso($ride->cancelled_at),
                'expired_at' => $this->iso($ride->expired_at),
                'no_show_at' => $this->iso($ride->no_show_at),
                'failed_at' => $this->iso($ride->failed_at),
            ]),
            'ended_reason' => $status->isTerminal() ? ($ride->cancel_reason ?? $ride->failure_reason) : null,
            'updated_at' => $this->iso($ride->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function positionFor(Ride $ride, DriverPosition $position): array
    {
        $status = $ride->statusEnum();
        $target = $status === RideStatus::InProgress
            ? [$ride->dropoff_lat, $ride->dropoff_lng]
            : [$ride->pickup_lat, $ride->pickup_lng];
        $eta = null;

        if ($status !== RideStatus::DriverArrived && $target[0] !== null && $target[1] !== null) {
            $eta = $this->reusableEta($ride, $status === RideStatus::InProgress ? 'dropoff' : 'pickup', $position, (float) $target[0], (float) $target[1]);
        }

        $staleAfter = (int) config('ehail_dispatch.heartbeat_stale_seconds');

        return [
            'lat' => round($position->lat, 6),
            'lng' => round($position->lng, 6),
            'heading' => $position->heading,
            'recorded_at' => $position->recordedAt->toIso8601String(),
            'age_s' => (int) $position->recordedAt->diffInSeconds(now(), true),
            'stale' => $position->recordedAt->lt(now()->subSeconds($staleAfter)),
            'eta_s' => $eta?->seconds,
            'eta_to' => $status === RideStatus::InProgress ? 'dropoff' : 'pickup',
            'eta_method' => $eta?->method,
        ];
    }

    /**
     * The driver's ETA for the passenger feed. A moving driver changes position on every poll, and a road route per
     * poll would cost a routing call every 3 s per live ride (paid providers bill each one; the route cache cannot
     * help because the position keeps changing). So the last answer is kept per ride for eta_reuse_seconds and counted
     * down by the time that passed; it is asked again sooner when the driver moved further than recompute_after_m
     * from where it was computed (a detour) or the target changed (D-1503). Same label (road or straight line).
     */
    private function reusableEta(Ride $ride, string $to, DriverPosition $position, float $toLat, float $toLng): ?EtaEstimate
    {
        $key = 'ehail:feed-eta:'.$ride->id.':'.$to;
        $reuse = (int) config('ehail_perf.eta_reuse_seconds', 30);
        $cached = $reuse > 0 ? Cache::get($key) : null;
        $now = now()->getTimestamp();

        if (is_array($cached) && $now - $cached['at'] < $reuse
            && Haversine::metres(new LatLng($cached['lat'], $cached['lng']), new LatLng($position->lat, $position->lng)) < (int) config('ehail_perf.eta_recompute_after_m', 400)) {
            return new EtaEstimate(max(0, $cached['seconds'] - ($now - $cached['at'])), $cached['distance'], $cached['method']);
        }

        try {
            $eta = $this->eta->estimate($position->lat, $position->lng, $toLat, $toLng);
        } catch (\Throwable) {
            return null;
        }

        if ($reuse > 0) {
            Cache::put($key, ['seconds' => $eta->seconds, 'distance' => $eta->distanceM, 'method' => $eta->method, 'lat' => $position->lat, 'lng' => $position->lng, 'at' => $now], $reuse);
        }

        return $eta;
    }

    /**
     * @return array<string, mixed>
     */
    private function driverOffer(RideOffer $offer, User $driver): array
    {
        $ride = Ride::find($offer->ride_id);

        return [
            'id' => $offer->id,
            'ride_id' => $offer->ride_id,
            'sent_at' => $this->iso($offer->sent_at),
            'expires_at' => $this->iso($offer->expires_at),
            'seconds_left' => max(0, (int) now()->diffInSeconds($offer->expires_at, false)),
            'pickup' => $this->place($ride->pickup_address, $ride->pickup_lat, $ride->pickup_lng),
            'dropoff' => ['address' => $ride->dropoff_address],
            'pickup_distance_m' => $offer->pickup_distance_m,
            'pickup_eta_s' => $offer->pickup_eta_s,
            'eta_method' => $offer->eta_method,
            'trip_distance_m' => $ride->route_distance_m,
            'trip_duration_s' => $ride->route_duration_s,
            'earnings_estimate_minor' => $this->earnings->estimateMinor($ride, $driver),
            'fare_total_minor' => $ride->quoted_total_minor,
            'currency' => $ride->currency,
            'category' => $ride->category_code ?? $ride->vehicle_type,
            'payment_method' => $ride->payment_method_kind,
            'passenger' => $this->passengerSummary($ride),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverRide(Ride $ride): array
    {
        $status = $ride->statusEnum();
        $wait = (int) config('ehail_dispatch.no_show_wait_minutes');

        return [
            'id' => $ride->id,
            'version' => (int) $ride->version,
            'status' => $status->value,
            'status_label' => $status->label(),
            'next_stage' => $status->nextStage()?->value,
            'pickup' => $this->place($ride->pickup_address, $ride->pickup_lat, $ride->pickup_lng),
            'dropoff' => $this->place($ride->dropoff_address, $ride->dropoff_lat, $ride->dropoff_lng),
            'passenger' => $this->passengerSummary($ride),
            'pin_required' => $ride->pin_hash !== null && (bool) config('ehail_dispatch.pin.required'),
            'arrived_at' => $this->iso($ride->arrived_at),
            'no_show_available_at' => $ride->arrived_at ? $this->iso($ride->arrived_at->copy()->addMinutes($wait)) : null,
            'fare_total_minor' => $ride->quoted_total_minor,
            'currency' => $ride->currency,
            'payment_method' => $ride->payment_method_kind,
            'trip_distance_m' => $ride->route_distance_m,
            'trip_duration_s' => $ride->route_duration_s,
            // Turn-by-turn is handed to the device's maps app (D-5): links to the next stop only.
            'navigation' => NavigationLinks::to($status === RideStatus::InProgress ? $ride->dropoff_lat : $ride->pickup_lat, $status === RideStatus::InProgress ? $ride->dropoff_lng : $ride->pickup_lng, $status === RideStatus::InProgress ? 'dropoff' : 'pickup'),
        ];
    }

    /**
     * @return array{first_name: string|null, rating_band: string}
     */
    private function passengerSummary(Ride $ride): array
    {
        $ratings = RideRating::where('rated_user_id', $ride->passenger_id)->where('rated_role', 'passenger')
            ->selectRaw('count(*) as n, avg(rating) as average')->first();
        $n = (int) ($ratings?->n ?? 0);
        $avg = (float) ($ratings?->average ?? 0);

        $band = match (true) {
            $n < 5 => 'new',
            $avg >= 4.8 => '4.8+',
            $avg >= 4.5 => '4.5-4.8',
            $avg >= 4.0 => '4.0-4.5',
            default => 'below 4.0',
        };

        return ['first_name' => $this->firstName($ride->passenger?->name), 'rating_band' => $band];
    }

    /**
     * @return array{address: string, lat: float|null, lng: float|null}
     */
    private function place(?string $address, $lat, $lng): array
    {
        return [
            'address' => (string) $address,
            'lat' => $lat === null ? null : round((float) $lat, 6),
            'lng' => $lng === null ? null : round((float) $lng, 6),
        ];
    }

    private function driverRating(int $driverId): ?float
    {
        // DriverProfile carries HasUserScope (own row only); the passenger reads their driver's.
        $rating = DriverProfile::withoutGlobalScope('user')->where('user_id', $driverId)->value('rating');

        return $rating === null || (float) $rating === 0.0 ? null : (float) $rating;
    }

    private function firstName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : strtok($name, ' ');
    }

    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }
}
