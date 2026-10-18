<?php

namespace App\Services\Notify;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two Dot.Notify events that have no in-app notification of their own: ride.accepted (to the
 * passenger) and driver.application_decided (to the applicant). The others (ride.cancelled,
 * ride.expired) ride on RideNoticeNotification's channel. Both methods never throw and send
 * nothing until the client is enabled; neither creates an in-app notification.
 *
 * Minimisation: only a display name, a vehicle description and a link leave. Never a phone number,
 * an ID or licence number, a plate, an address or a coordinate.
 */
class EhailNotifyEvents
{
    /** Longest reviewer note forwarded (the applicant sees the same note in the app). */
    private const NOTE_LIMIT = 300;

    public function __construct(private readonly NotifyClient $notify) {}

    public function rideAccepted(Ride $ride): void
    {
        try {
            if (! $this->notify->enabled() || $ride->passenger === null || $ride->driver === null) {
                return;
            }

            $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;

            $this->notify->queue($ride->passenger, 'ride.accepted', $ride->id, 'accepted-'.(int) $ride->reassignment_count, [
                'driver_name' => (string) $ride->driver->name,
                'vehicle' => $vehicle === null ? '' : trim(implode(' ', array_filter([$vehicle->color, $vehicle->make, $vehicle->model]))),
                'url' => $this->notify->pageUrl('rides.show', $ride),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not prepare the ride.accepted Notify event.', ['ride_id' => $ride->id, 'error' => class_basename($e)]);
        }
    }

    /** @param  'approved'|'rejected'  $decision */
    public function driverApplicationDecided(DriverProfile $profile, string $decision): void
    {
        try {
            if (! $this->notify->enabled() || $profile->user === null) {
                return;
            }

            $this->notify->queue($profile->user, 'driver.application_decided', $profile->id, $decision, [
                'decision' => $decision,
                'fleet_name' => (string) Fleet::query()->whereKey($profile->fleet_id)->value('name'),
                'note' => $decision === 'rejected' ? mb_substr((string) $profile->rejected_reason, 0, self::NOTE_LIMIT) : '',
                'url' => $this->notify->pageUrl('drivers.show', $profile),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not prepare the driver.application_decided Notify event.', ['driver_profile_id' => $profile->id, 'error' => class_basename($e)]);
        }
    }
}
