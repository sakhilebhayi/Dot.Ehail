<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\RideOffer;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

/**
 * The fairness terms of CandidateScorer (docs/R46_DISPATCH.md "Fairness").
 */
class FairnessTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        // PIN entry is tested elsewhere; these rides are driven start to finish quickly.
        config(['ehail_dispatch.pin.required' => false]);
    }

    private function completeTrip(Ride $ride, User $driver): void
    {
        $dispatcher = app(Dispatcher::class);
        $offer = RideOffer::where('pending_ride_id', $ride->id)->firstOrFail();
        $dispatcher->offerResponse($offer, $driver, 'accept');

        foreach ([RideStatus::DriverArriving, RideStatus::DriverArrived, RideStatus::InProgress, RideStatus::Completed] as $stage) {
            $dispatcher->advance($ride->fresh(), $driver, $stage);
        }
    }

    public function test_two_equally_placed_drivers_alternate_over_many_requests(): void
    {
        [$lat, $lng] = $this->northOfPickup(500);
        $a = $this->onlineDriverAt($lat, $lng);
        $b = $this->onlineDriverAt($lat, $lng);
        $winners = [];

        for ($i = 0; $i < 12; $i++) {
            $this->travel(2)->minutes();
            $this->heartbeat($a, [$lat, $lng]);
            $this->heartbeat($b, [$lat, $lng]);

            $ride = $this->requestRide();
            $offer = RideOffer::where('pending_ride_id', $ride->id)->firstOrFail();
            $winner = $offer->driver_id === $a->id ? $a : $b;
            $winners[] = $winner->id;

            $this->completeTrip($ride, $winner);
        }

        $counts = array_count_values($winners);
        $this->assertSame(6, $counts[$a->id]);
        $this->assertSame(6, $counts[$b->id]);

        for ($i = 1; $i < count($winners); $i++) {
            $this->assertNotSame($winners[$i - 1], $winners[$i], "request {$i} went to the same driver twice in a row");
        }
    }

    public function test_idle_time_breaks_a_tie_in_favour_of_the_driver_waiting_longest(): void
    {
        [$lat, $lng] = $this->northOfPickup(500);
        $longWaiting = $this->onlineDriverAt($lat, $lng);
        $this->travel(10)->minutes();
        $justOnline = $this->onlineDriverAt($lat, $lng);
        $this->heartbeat($longWaiting, [$lat, $lng]);

        $ride = $this->requestRide();
        $offer = RideOffer::where('pending_ride_id', $ride->id)->first();

        $this->assertSame($longWaiting->id, $offer->driver_id);
        $this->assertSame(60.0, (float) $offer->score_terms['idle_credit_s'], '10 min idle x 0.1 = 60 s credit');
    }

    public function test_idle_credit_is_capped_so_a_far_driver_cannot_beat_a_near_one_by_waiting_forever(): void
    {
        $far = $this->onlineDriverAt(...$this->northOfPickup(6000));
        $this->travel(5)->hours();
        $near = $this->onlineDriverAt(...$this->northOfPickup(300));
        $this->heartbeat($far, $this->northOfPickup(6000));

        $ride = $this->requestRide();

        $this->assertSame($near->id, RideOffer::where('pending_ride_id', $ride->id)->value('driver_id'));
        // The far driver was scored too; the cap (30 min x 0.1 = 180 s) is in the weights logged.
        $this->assertSame(1800, RideOffer::where('pending_ride_id', $ride->id)->first()->score_terms['weights']['idle_cap_s']);
    }

    public function test_declines_cost_only_a_capped_temporary_cooldown_never_exclusion(): void
    {
        [$lat, $lng] = $this->northOfPickup(400);
        $decliner = $this->onlineDriverAt($lat, $lng);
        $dispatcher = app(Dispatcher::class);

        // Three declines inside ten minutes.
        for ($i = 0; $i < 3; $i++) {
            $ride = $this->requestRide();
            $dispatcher->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $decliner, 'decline', 'too_far');
            $this->travel(30)->seconds();
            $this->heartbeat($decliner, [$lat, $lng]);
        }

        // Still offered (nobody else is around): a cooldown is a penalty, not a ban.
        $ride = $this->requestRide();
        $offer = RideOffer::where('pending_ride_id', $ride->id)->first();
        $this->assertSame($decliner->id, $offer->driver_id);
        $this->assertSame(60, $offer->score_terms['cooldown_s']);
        $this->assertSame(3, $offer->score_terms['recent_misses']);
        $dispatcher->offerResponse($offer, $decliner, 'decline');

        // After the cooldown and the window pass, the penalty is gone.
        $this->travel(11)->minutes();
        $this->heartbeat($decliner, [$lat, $lng]);
        $ride = $this->requestRide();
        $this->assertSame(0, RideOffer::where('pending_ride_id', $ride->id)->first()->score_terms['cooldown_s']);
    }

    public function test_a_driver_in_cooldown_loses_a_close_call(): void
    {
        $decliner = $this->onlineDriverAt(...$this->northOfPickup(300));
        $dispatcher = app(Dispatcher::class);

        for ($i = 0; $i < 3; $i++) {
            $ride = $this->requestRide();
            $dispatcher->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $decliner, 'decline');
        }

        // A rival 100 m further away (about 20 s more ETA) wins while the 60 s cooldown applies.
        $rival = $this->onlineDriverAt(...$this->northOfPickup(400));
        $this->heartbeat($decliner, $this->northOfPickup(300));
        $ride = $this->requestRide();
        $this->assertSame($rival->id, RideOffer::where('pending_ride_id', $ride->id)->value('driver_id'));
    }
}
