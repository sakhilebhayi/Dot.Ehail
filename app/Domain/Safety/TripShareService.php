<?php

namespace App\Domain\Safety;

use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Safety\Mail\TripShareMail;
use App\Domain\Safety\Models\TripShare;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
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
     * What the public page may show. Nothing here identifies a person beyond a first name.
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

        return [
            'state' => $progress['state'],
            'state_label' => $this->stateLabel((string) $progress['state']),
            'ended' => RideSafety::isTerminal($ride),
            'passenger_first_name' => RideSafety::firstName($ride->passenger?->name, 'The passenger'),
            'driver_first_name' => $ride->driver_id ? RideSafety::firstName($ride->driver?->name, 'The driver') : null,
            'vehicle' => $vehicle ? [
                'description' => trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}"),
                'registration' => $vehicle->plate_number,
            ] : null,
            'approximate_position' => $progress['driver_position'] ? [
                'lat' => round($progress['driver_position']['lat'], $decimals),
                'lng' => round($progress['driver_position']['lng'], $decimals),
                'at' => $progress['position_at'],
            ] : null,
            'eta_seconds' => $progress['eta_seconds'],
            'expires_at' => $this->effectiveExpiry($share)->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ];
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
