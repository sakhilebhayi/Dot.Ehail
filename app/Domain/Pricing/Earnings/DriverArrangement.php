<?php

namespace App\Domain\Pricing\Earnings;

use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Models\DriverProfile;
use App\Models\Ride;

/**
 * Who drives and under which arrangement: market, category and fleet decide the commission plan.
 */
final readonly class DriverArrangement
{
    public function __construct(
        public ?int $driverUserId,
        public ?int $fleetId,
        public string $country,
        public string $currency,
        public ?int $serviceAreaId,
        public ?int $categoryId,
    ) {}

    /**
     * The arrangement for a ride and a driver (the assigned driver by default).
     */
    public static function forRide(Ride $ride, ?int $driverUserId = null): ?self
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();

        if ($fare === null) {
            return null;
        }

        $driverUserId ??= $ride->driver_id;
        $quote = $fare->fare_quote_id !== null ? FareQuote::query()->find($fare->fare_quote_id) : null;
        $fleetId = $driverUserId === null ? null : DriverProfile::withoutGlobalScope('user')->where('user_id', $driverUserId)->value('fleet_id');

        return new self(
            $driverUserId,
            $fleetId !== null ? (int) $fleetId : null,
            (string) ($fare->ruleSet?->country ?? config('ehail_pricing.default_country')),
            $fare->currency,
            $quote?->service_area_id,
            $fare->ride_category_id,
        );
    }

    public static function forQuote(FareQuote $quote, ?int $driverUserId = null): self
    {
        $fleetId = $driverUserId === null ? null : DriverProfile::withoutGlobalScope('user')->where('user_id', $driverUserId)->value('fleet_id');

        return new self($driverUserId, $fleetId !== null ? (int) $fleetId : null, (string) ($quote->ruleSet?->country ?? config('ehail_pricing.default_country')), $quote->currency, $quote->service_area_id, $quote->ride_category_id);
    }
}
