<?php

namespace App\Domain\Dispatch\Data;

use Illuminate\Support\Carbon;

/**
 * The parts of a pricing FareQuote dispatch stores on the ride (the full
 * breakdown stays in the pricing domain's fare_quotes; the ride keeps the id
 * and this snapshot).
 */
final class LockedQuote
{
    /**
     * @param  list<array{code: string, label: string, amount_minor: int}>  $components
     */
    public function __construct(
        public readonly string $id,
        public readonly int $passengerId,
        public readonly string $category,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly Carbon $expiresAt,
        public readonly float $pickupLat,
        public readonly float $pickupLng,
        public readonly string $pickupAddress,
        public readonly float $dropoffLat,
        public readonly float $dropoffLng,
        public readonly string $dropoffAddress,
        public readonly ?int $routeDistanceM = null,
        public readonly ?int $routeDurationS = null,
        public readonly array $components = [],
        public readonly ?string $ruleSetId = null,
        public readonly ?string $ruleVersion = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'total_minor' => $this->totalMinor,
            'currency' => $this->currency,
            'components' => $this->components,
            'rule_set_id' => $this->ruleSetId,
            'rule_version' => $this->ruleVersion,
            'route_distance_m' => $this->routeDistanceM,
            'route_duration_s' => $this->routeDurationS,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
