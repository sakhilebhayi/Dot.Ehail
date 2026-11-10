<?php

namespace App\Domain\Pricing\Finalisation;

/**
 * What actually happened on a trip, as measured by the geo DistanceMeter (billable road metres, method, quality,
 * flags) and the ride's timestamps, plus what the passenger changed. Dispatch builds this at completion.
 *
 * Quality "insufficient" or the geo flag "unresolved_gap" mean the distance cannot be trusted: the fare is then never
 * recalculated from it (the quoted fare stands; waiting and tolls still apply; ops may adjust with a reason).
 */
final readonly class TripActuals
{
    /**
     * @param  ?int  $tollsMinor  tolls actually paid (pass-through rule sets only); null = none reported
     * @param  ?list<string>  $dropoffFeeCodes  zone fee codes at the ACTUAL drop-off when it changed; null = as quoted
     * @param  list<string>  $flags  geo TripDistance flags
     */
    public function __construct(
        public int $billableDistanceM,
        public int $durationS,
        public int $waitingSeconds = 0,
        public ?int $tollsMinor = null,
        public bool $destinationChangedByPassenger = false,
        public bool $stopsAddedByPassenger = false,
        public bool $endedEarlyByPassenger = false,
        public string $distanceMethod = 'unknown',
        public string $distanceQuality = 'good',
        public array $flags = [],
        public ?array $dropoffFeeCodes = null,
    ) {}

    /**
     * From the geo domain's App\Domain\Geo\Distance\TripDistance (duck-typed so pricing does not depend on geo).
     */
    public static function fromTripDistance(object $distance, int $durationS, int $waitingSeconds = 0, ?int $tollsMinor = null, bool $destinationChangedByPassenger = false, bool $stopsAddedByPassenger = false, bool $endedEarlyByPassenger = false): self
    {
        return new self(
            (int) $distance->billableM,
            $durationS,
            $waitingSeconds,
            $tollsMinor,
            $destinationChangedByPassenger,
            $stopsAddedByPassenger,
            $endedEarlyByPassenger,
            (string) $distance->method,
            (string) $distance->quality,
            array_values((array) $distance->flags),
        );
    }

    public function passengerChangedTheTrip(): bool
    {
        return $this->destinationChangedByPassenger || $this->stopsAddedByPassenger || $this->endedEarlyByPassenger;
    }

    public function distanceIsTrustworthy(): bool
    {
        return $this->distanceQuality !== 'insufficient' && ! in_array('unresolved_gap', $this->flags, true) && $this->distanceMethod !== 'no_data';
    }
}
