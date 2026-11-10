<?php

namespace App\Domain\Geo\Locations;

use App\Domain\Geo\Models\DriverLocation;

final readonly class NearbyDriver
{
    public function __construct(public int $driverId, public float $distanceM, public DriverLocation $location) {}
}
