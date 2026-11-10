<?php

namespace App\Domain\Geo\Deviation;

use App\Domain\Geo\LatLng;
use Carbon\CarbonImmutable;

/**
 * Something about a trip in progress that a person should look at. kind: route_deviation | unusual_stop.
 * For route_deviation, metres is the distance off the planned route; for unusual_stop, seconds is how long the
 * vehicle has been stationary.
 */
final readonly class Deviation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $kind,
        public int $rideId,
        public int $driverId,
        public LatLng $position,
        public CarbonImmutable $detectedAt,
        public ?int $metres = null,
        public ?int $seconds = null,
        public array $context = [],
    ) {}
}
