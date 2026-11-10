<?php

namespace App\Domain\Safety;

use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Passenger\PlaceLabel;
use App\Domain\Safety\Mail\TripShareMail;
use App\Domain\Safety\Models\TripShare;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Ui\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Public trip-share links (/t/{token}). The token is 256 bits from random_bytes, URL-safe; only
 * its SHA-256 is stored, so a database leak does not leak working links. A link stops working when
 * revoked, at its hard expiry, or 30 minutes after the trip ends, whichever is first. The public
 * payload carries first names, the vehicle and the trip state, never addresses, phone numbers,
 * emails, ids or the full name.
 */
class TripShareService
{
    private const MAX_ACTIVE_PER_RIDE = 10;

    public function __construct(
        private readonly TripProgressSource $progress,
        private readonly MailDelivery $mail,
        private readonly TrustedContactService $contacts,
    ) {}

    /**
     * @return array{share: TripShare, token: string, url: string, delivery: array{status: string, error: ?string}|null}
     */
    public function create(Ride $ride, User $creator, ?TrustedContact $contact = null): array
    {
        if (RideSafety::roleOf($ride, $creator) === null) {
            throw ValidationException::withMessages(['ride' => 'Only the passenger or the driver of this ride can share it.']);
        }

        if (RideSafety::isTerminal($ride)) {
            throw ValidationException::withMessages(['ride' => 'This trip has ended; there is nothing to share.']);
        }

        if ($contact !== null && ($contact->user_id !== $creator->id || ! $contact->isUsable())) {
            throw ValidationException::withMessages(['trusted_contact_id' => 'Choose one of your confirmed trusted contacts.']);
        }

        $active = TripShare::query()->where('ride_id', $ride->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count();

        if ($active >= self::MAX_ACTIVE_PER_RIDE) {
            throw ValidationException::withMessages(['ride' => 'This trip already has the maximum number of share links. Revoke one first.']);
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $share = TripShare::create([
            'ride_id' => $ride->id,
            'created_by' => $creator->id,
            'trusted_contact_id' => $contact?->id,
            'token_hash' => TripShare::hashToken($token),
            'expires_at' => now()->addHours((int) config('ehail_safety.trip_share.max_lifetime_hours', 6)),
        ]);

        $url = route('trip-share.show', $token);
        $delivery = null;

        if ($contact !== null) {
            $delivery = $this->mail->send((string) $contact->email, new TripShareMail(
                (string) $contact->name,
                RideSafety::firstName($creator->name, 'Someone'),
                $url,
                $share->expires_at->copy()->timezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y, H:i').' '.\App\Support\Ui\LocalTime::label(),
                $this->contacts->optOutUrl($contact),
            ));
        }

        return ['share' => $share, 'token' => $token, 'url' => $url, 'delivery' => $delivery];
    }

    /** The live share for a token, or null when it does not exist, was revoked or has expired. */
    public function resolve(string $token): ?TripShare
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $share = TripShare::query()->with('ride')->where('token_hash', TripShare::hashToken($token))->first();

        if ($share === null || $share->revoked_at !== null || $share->ride === null) {
            return null;
        }

        // A link lives only while its creator is still a party to the ride: a driver who backed out, or was
        // replaced, must not keep following the car that now carries the passenger (R-46 security S-05).
        $creator = $share->creator;
        if ($creator === null || RideSafety::roleOf($share->ride, $creator) === null) {
            return null;
        }

        return $this->effectiveExpiry($share)->isFuture() ? $share : null;
    }

    public function effectiveExpiry(TripShare $share): Carbon
    {
        $ended = $share->ride ? RideSafety::endedAt($share->ride) : null;
        $afterTrip = $ended?->copy()->addMinutes((int) config('ehail_safety.trip_share.after_trip_minutes', 30));

        return $afterTrip !== null && $afterTrip->lt($share->expires_at) ? $afterTrip : $share->expires_at;
    }

    public function revoke(TripShare $share): TripShare
    {
        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return $share;
    }

    public function recordView(TripShare $share): void
    {
        TripShare::query()->whereKey($share->id)->update(['last_viewed_at' => now(), 'view_count' => $share->view_count + 1]);
    }

    /**
     * What the public page may show. Nothing here identifies a person beyond a first name, and nothing names a
     * street or a building: the two ends of the trip are given as areas only (D-2011). Times come ready to read,
     * in the service's own time zone, so the page never formats a time itself.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(TripShare $share): array
    {
        $ride = $share->ride;
        $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;
        // Position only while the ride holds its driver (S-05): a ride flagged needs_review has released the driver.
        $progress = RideSafety::isTerminal($ride) || ! RideTracking::isTracking($ride) ? ['state' => (string) $ride->status, 'driver_position' => null, 'position_at' => null, 'eta_seconds' => null, 'eta_source' => null] : $this->progress->progress($ride);
        $decimals = (int) config('ehail_safety.trip_share.position_decimals', 3);
        $state = (string) $progress['state'];
        $etaTo = $progress['eta_seconds'] === null ? null : ($state === 'in_progress' ? 'dropoff' : 'pickup');
        $sharedBy = (int) $share->created_by === (int) $ride->driver_id && (int) $share->created_by !== (int) $ride->passenger_id ? 'driver' : 'passenger';
        $expires = $this->effectiveExpiry($share);

        return [
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'ended' => RideSafety::isTerminal($ride),
            'passenger_first_name' => RideSafety::firstName($ride->passenger?->name, 'The passenger'),
            'driver_first_name' => $ride->driver_id ? RideSafety::firstName($ride->driver?->name, 'The driver') : null,
            'sharer_first_name' => RideSafety::firstName($share->creator?->name, $sharedBy === 'driver' ? 'The driver' : 'The passenger'),
            'shared_by' => $sharedBy,
            'from_area' => PlaceLabel::area($ride->pickup_address) ?: null,
            'to_area' => PlaceLabel::area($ride->dropoff_address) ?: null,
            'vehicle' => $vehicle ? [
                'description' => trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}"),
                'registration' => $vehicle->plate_number,
            ] : null,
            'approximate_position' => $progress['driver_position'] ? [
                'lat' => round($progress['driver_position']['lat'], $decimals),
                'lng' => round($progress['driver_position']['lng'], $decimals),
                'at' => $progress['position_at'],
                'time' => $progress['position_at'] ? $this->local($progress['position_at'], 'H:i:s') : null,
            ] : null,
            'eta_seconds' => $progress['eta_seconds'],
            'eta_to' => $etaTo,
            'eta_text' => $etaTo === null ? null : sprintf('About %d min to %s', max(1, (int) round($progress['eta_seconds'] / 60)), $etaTo === 'dropoff' ? 'drop-off' : 'pickup'),
            'route_key' => TripShareRoute::key($ride),
            'expires_at' => $expires->toIso8601String(),
            'expires_time' => $this->local($expires, 'H:i, j M'),
            'checked_at' => now()->toIso8601String(),
            'checked_time' => $this->local(now(), 'H:i:s'),
            'time_zone' => LocalTime::label(),
        ];
    }

    /**
     * What the page's map needs, sent once with the page and never in the poll: the tile source and the planned
     * road line with both ends cut off. No pickup or drop-off point.
     *
     * @return array{config: array<string, mixed>, route: ?string}
     */
    public function publicMap(TripShare $share): array
    {
        return [
            'config' => [
                'tiles' => [
                    'url' => (string) config('ehail-geo.tiles.url'),
                    'attribution' => (string) config('ehail-geo.tiles.attribution'),
                    'maxZoom' => (int) config('ehail-geo.tiles.max_zoom'),
                    'tileSize' => (int) config('ehail-geo.tiles.tile_size'),
                    'reportIssueUrl' => config('ehail-geo.tiles.kind') === 'osm' ? 'https://www.openstreetmap.org/fixthemap' : null,
                ],
                'defaultCenter' => ['lat' => -26.2041, 'lng' => 28.0473],
                'defaultZoom' => 11,
            ],
            'route' => RideSafety::isTerminal($share->ride) ? null : TripShareRoute::trimmed($share->ride),
        ];
    }

    /** A moment in the service's display time zone (South African Standard Time unless the market says otherwise). */
    private function local(\DateTimeInterface|string $at, string $format): string
    {
        return (string) LocalTime::format($at, $format);
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'requested', 'searching' => 'Looking for a driver',
            'accepted', 'driver_assigned' => 'Driver assigned',
            'en_route', 'driver_arriving' => 'Driver on the way to pickup',
            'arrived', 'driver_arrived' => 'Driver at pickup',
            'in_progress' => 'Trip in progress',
            'completed' => 'Trip completed',
            'needs_review' => 'Trip under review by operations',
            default => 'Trip ended',
        };
    }
}
