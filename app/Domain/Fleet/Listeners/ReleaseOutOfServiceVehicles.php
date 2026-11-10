<?php

namespace App\Domain\Fleet\Listeners;

use App\Domain\Fleet\FleetVehicles;
use App\Domain\Rides\Events\RideDomainEvent;
use App\Models\Ride;
use App\Models\Vehicle;

/**
 * A vehicle taken out of service during a trip: when that trip ends (completed, cancelled, no-show, failed), its
 * driver is taken offline instead of being offered the next ride.
 */
class ReleaseOutOfServiceVehicles
{
    public function __construct(private readonly FleetVehicles $vehicles) {}

    public function handle(RideDomainEvent $event): void
    {
        $vehicleId = Ride::query()->whereKey($event->rideId)->value('vehicle_id');
        $vehicle = $vehicleId ? Vehicle::query()->find($vehicleId) : null;

        if ($vehicle !== null && ($vehicle->out_of_service_at !== null || $vehicle->retired_at !== null)) {
            $this->vehicles->releaseDriverOf($vehicle);
        }
    }
}
