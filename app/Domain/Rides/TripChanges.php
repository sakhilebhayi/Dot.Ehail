<?php

namespace App\Domain\Rides;

use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\RideRoute;
use App\Domain\Geo\Zones\ZoneResolver;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A passenger changes the trip after a driver is assigned: a new destination or an extra stop (D-712).
 * The change is a timeline fact ("trip_changed", reason destination_changed | stop_added) with who and
 * where; pricing's finalisation reads it, so a longer trip the passenger asked for is recalculated without
 * the overrun cap and shown with its reason ("route changed at your request"). A destination change also
 * moves the ride's drop-off and drops the planned route so geo plans the new one; a stop is recorded only
 * (multi-stop routes are not planned, so deviation checks may prompt a check-in during the detour).
 */
class TripChanges
{
    public const KINDS = ['destination' => 'destination_changed', 'stop' => 'stop_added'];

    public function __construct(private readonly RideTimeline $timeline) {}

    /**
     * A business ride is billed to the company, and a passenger's change is recalculated without the overrun cap:
     * mid-trip changes could carry a traveller past the policy's areas, per-ride maximum, approval threshold and
     * spend limits, none of which is checked again at completion (R-46 security S-19). So on a business ride only
     * an approver (account admin or travel manager) may change the trip, and never outside the policy's areas.
     */
    private function assertBusinessPolicyAllows(Ride $ride, float $lat, float $lng): void
    {
        $trip = BusinessTrip::query()->where('ride_id', $ride->id)->with('booker')->first();

        if ($trip === null) {
            return;
        }

        if (! ($trip->booker?->isApprover() ?? false)) {
            throw new DispatchRefused('business_change_not_allowed', 'This ride is billed to your company, so it cannot be extended mid-trip. Ask the driver to end the trip here and book the next leg.', 409);
        }

        $areas = array_map('intval', (array) ($trip->policy_snapshot['service_area_ids'] ?? []));

        if ($areas !== []) {
            $area = app(ZoneResolver::class)->serviceAreaFor(new LatLng($lat, $lng));

            if ($area === null || ! in_array((int) $area->id, $areas, true)) {
                throw new DispatchRefused('area_not_allowed', 'That place is outside the areas your company\'s ride policy allows.', 422);
            }
        }
    }

    public function record(Ride $ride, User $passenger, string $kind, float $lat, float $lng, ?string $address): Ride
    {
        $reason = self::KINDS[$kind] ?? throw new DispatchRefused('unknown_change', 'Choose a new destination or an extra stop.', 422);

        return DB::transaction(function () use ($ride, $passenger, $kind, $reason, $lat, $lng, $address) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            if ($locked->passenger_id !== $passenger->id) {
                throw new DispatchRefused('not_your_ride', 'That ride is not yours.', 403);
            }

            if (! in_array($locked->statusEnum(), [RideStatus::DriverAssigned, RideStatus::DriverArriving, RideStatus::DriverArrived, RideStatus::InProgress], true)) {
                throw new DispatchRefused('not_changeable', 'The trip can be changed only while a driver is on the way or the trip is under way.', 409);
            }

            $this->assertBusinessPolicyAllows($locked, $lat, $lng);

            $label = $address !== null && trim($address) !== '' ? mb_substr(trim($address), 0, 255) : sprintf('Pinned location (%.5f, %.5f)', $lat, $lng);
            $context = ['kind' => $kind, 'lat' => round($lat, 6), 'lng' => round($lng, 6), 'address' => $label];

            if ($kind === 'destination') {
                $context['previous'] = ['lat' => $locked->dropoff_lat, 'lng' => $locked->dropoff_lng, 'address' => $locked->dropoff_address];
                $locked->forceFill(['dropoff_lat' => round($lat, 7), 'dropoff_lng' => round($lng, 7), 'dropoff_address' => $label])->save();
                RideRoute::query()->where('ride_id', $locked->id)->whereNull('trail_purged_at')->delete();
            } else {
                // The feed version moves so both apps see the change on their next poll.
                $locked->forceFill(['updated_at' => now()])->save();
            }

            $this->timeline->append($locked, 'trip_changed', RideActor::Passenger, $passenger->id, $reason, $context);

            return $locked;
        });
    }
}
