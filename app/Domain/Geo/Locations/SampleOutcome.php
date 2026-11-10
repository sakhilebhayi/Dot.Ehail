<?php

namespace App\Domain\Geo\Locations;

/**
 * What happened to one posted sample. reason is null when accepted; otherwise one of
 * inaccurate | future | stale | out_of_order | implausible_jump.
 */
final readonly class SampleOutcome
{
    public function __construct(
        public bool $accepted,
        public ?string $reason = null,
        public bool $latestUpdated = false,
        public bool $tripPointStored = false,
    ) {}

    /**
     * @return array{accepted: bool, reason: ?string, latest_updated: bool, trip_point_stored: bool}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'reason' => $this->reason,
            'latest_updated' => $this->latestUpdated,
            'trip_point_stored' => $this->tripPointStored,
        ];
    }
}
