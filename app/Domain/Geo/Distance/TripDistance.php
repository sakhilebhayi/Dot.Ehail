<?php

namespace App\Domain\Geo\Distance;

/**
 * The measured distance of a trip (docs/R46_GEO.md, "distance vocabulary").
 *  - travelledM: what the filtered GPS trail shows, gaps bridged by straight lines.
 *  - billableM:  the distance pricing may use: travelled, with gaps re-estimated by road routes where a routing
 *                provider answered. When a gap could not be re-estimated it stays a straight line (shorter than the
 *                road, so in the passenger's favour) and the 'unresolved_gap' flag tells pricing to keep the quote.
 *  - method:     gps | gps_with_route_gaps | gps_with_straight_gaps | no_data | stored_summary
 *  - quality:    good | degraded | insufficient
 *
 * @phpstan-type Flags list<string>
 */
final readonly class TripDistance
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public int $travelledM,
        public int $billableM,
        public string $method,
        public string $quality,
        public array $flags = [],
        public int $pointsUsed = 0,
        public int $pointsDropped = 0,
        public int $gapsRerouted = 0,
    ) {}

    /**
     * @return array{travelled_m: int, billable_m: int, method: string, quality: string, flags: list<string>, points_used: int, points_dropped: int, gaps_rerouted: int}
     */
    public function toArray(): array
    {
        return [
            'travelled_m' => $this->travelledM,
            'billable_m' => $this->billableM,
            'method' => $this->method,
            'quality' => $this->quality,
            'flags' => $this->flags,
            'points_used' => $this->pointsUsed,
            'points_dropped' => $this->pointsDropped,
            'gaps_rerouted' => $this->gapsRerouted,
        ];
    }
}
