<?php

namespace App\Domain\Dispatch\Data;

use Illuminate\Support\Carbon;

final class DriverPosition
{
    public function __construct(
        public readonly int $userId,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?int $heading,
        public readonly Carbon $recordedAt,
        public readonly ?int $vehicleId = null,
        public readonly ?int $straightLineM = null,
        // FeedVersion of this position (microseconds), for the passenger feed.
        public readonly int $version = 0,
    ) {}
}
