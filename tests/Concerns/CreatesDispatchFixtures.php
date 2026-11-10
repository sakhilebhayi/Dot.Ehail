<?php

namespace Tests\Concerns;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Rides\RideRequestService;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Tests\Doubles\FakeFareQuotes;

/**
 * Builders for the R-46 dispatch tests: real models and services
 * throughout; only the pricing seam (not merged yet) is a test double.
 */
trait CreatesDispatchFixtures
{
    use ApprovesDriverDocuments;

    protected FakeFareQuotes $quotes;

    /** Kloof Street, Gardens, Cape Town: the pickup every fake quote uses unless told otherwise. */
    protected const PICKUP = [-33.9249, 18.4241];

    protected function fakeQuotes(): FakeFareQuotes
    {
        $this->quotes = new FakeFareQuotes;
        $this->app->instance(FareQuoteGateway::class, $this->quotes);
        // Dispatch tests run without a payment provider; the money resolver then needs cash to be accepted
        // (otherwise there is no way to pay and requests are refused, D-706).
        config(['money.cash.enabled' => true]);

        return $this->quotes;
    }

    protected function passenger(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /**
     * An approved driver with an active vehicle and every mandatory document approved (the safety
     * verification gate), online, with a fresh heartbeat at the given point.
     */
    protected function onlineDriverAt(float $lat, float $lng, string $vehicleType = 'standard', ?string $name = null): User
    {
        $user = User::factory()->withPersonalTeam()->create($name ? ['name' => $name] : []);
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);
        $vehicle = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => $vehicleType, 'seats' => $vehicleType === 'suv' ? 6 : 4]);
        $this->approveAllDocuments($profile, $vehicle);

        $availability = app(AvailabilityService::class);
        $availability->goOnline($user);
        $availability->heartbeat($user, $lat, $lng, 90);

        return $user;
    }

    /**
     * Metres north of the standard pickup (1 degree of latitude is about 111.32 km).
     *
     * @return array{0: float, 1: float}
     */
    protected function northOfPickup(int $metres): array
    {
        return [self::PICKUP[0] + $metres / 111320, self::PICKUP[1]];
    }

    /**
     * @param  array<string, mixed>  $quoteOverrides
     */
    protected function requestRide(?User $passenger = null, array $quoteOverrides = []): Ride
    {
        $passenger ??= $this->passenger();
        $quote = $this->quotes->issue($passenger, $quoteOverrides);

        return app(RideRequestService::class)->request($passenger, $quote->id);
    }

    protected function heartbeat(User $driver, ?array $at = null): void
    {
        $at ??= self::PICKUP;
        app(AvailabilityService::class)->heartbeat($driver, $at[0], $at[1]);
    }
}
