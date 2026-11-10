<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\RideTransitions;
use App\Domain\Rides\TransitionOptions;
use App\Domain\Rides\TripPin;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\InvalidRideTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * Property test: random sequences of attempted transitions by random actors
 * (right and wrong users, random targets, random clock jumps) never leave the
 * system in an invalid state. Invariants checked after every attempt:
 *  1. every status is a v2 state; a terminal ride never changes again;
 *  2. a ride holds at most one driver, and holds one exactly in the driver states;
 *  3. no driver holds two rides; driver_availability agrees with the rides;
 *  4. a passenger has at most one active ride.
 * Seeds are fixed so a failure is reproducible.
 */
class StateMachinePropertyTest extends TestCase
{
    use CreatesDispatchFixtures, CreatesEhailFixtures, RefreshDatabase {
        CreatesDispatchFixtures::passenger insteadof CreatesEhailFixtures;
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function seeds(): array
    {
        return ['seed 7' => [7], 'seed 1234' => [1234], 'seed 98765' => [98765], 'seed 4242' => [4242]];
    }

    #[DataProvider('seeds')]
    public function test_random_transition_sequences_never_reach_an_invalid_state(int $seed): void
    {
        mt_srand($seed);
        $machine = app(RideStateMachine::class);
        $drivers = [];

        for ($i = 0; $i < 3; $i++) {
            $drivers[] = $this->onlineDriverAt(...$this->northOfPickup(100 * ($i + 1)));
        }

        $operator = $this->makeOperator();
        $passengers = [];
        $rides = [];

        for ($i = 0; $i < 4; $i++) {
            $passengers[] = $passenger = $this->passenger();
            $rides[] = $this->newRide($passenger, $i % 2 === 0);
        }

        $states = RideStatus::cases();
        $actors = RideActor::cases();
        $terminalSeen = [];
        $applied = 0;

        for ($step = 0; $step < 400; $step++) {
            $open = array_values(array_filter($rides, fn (Ride $r) => ! isset($terminalSeen[$r->id])));
            $pool = $open !== [] && mt_rand(0, 9) < 9 ? $open : $rides;
            $ride = $pool[mt_rand(0, count($pool) - 1)]->fresh();
            $edges = RideTransitions::from($ride->statusEnum());

            // Biased toward edges that exist (so the walk goes deep), but still often
            // a random target and a random actor (so the refusals are exercised).
            if ($edges !== [] && mt_rand(0, 9) < 7) {
                $target = array_rand($edges);
                $to = RideStatus::from($target);
                $actor = mt_rand(0, 9) < 8 ? $edges[$target]['actors'][mt_rand(0, count($edges[$target]['actors']) - 1)] : $actors[mt_rand(0, count($actors) - 1)];
            } else {
                $to = $states[mt_rand(0, count($states) - 1)];
                $actor = $actors[mt_rand(0, count($actors) - 1)];
            }
            $user = match ($actor) {
                RideActor::Passenger => mt_rand(0, 4) === 0 ? $passengers[mt_rand(0, 3)] : User::find($ride->passenger_id),
                RideActor::Driver => $ride->driver_id !== null && mt_rand(0, 9) < 7 ? User::find($ride->driver_id) : $drivers[mt_rand(0, 2)],
                RideActor::Operator => $operator,
                RideActor::System => null,
            };

            if (mt_rand(0, 9) === 0) {
                $this->travel(mt_rand(1, 12))->minutes();
            }

            $before = $ride->status;

            try {
                $machine->transition($ride, $to, $actor, $user, new TransitionOptions(
                    reason: 'other',
                    driver: $drivers[mt_rand(0, 2)],
                    pinVerified: (bool) mt_rand(0, 1),
                    finalFareMinor: mt_rand(0, 1) ? 5000 : null,
                    force: (bool) mt_rand(0, 1),
                ));
                $applied++;
            } catch (InvalidRideTransition|DispatchRefused) {
                $this->assertSame($before, $ride->fresh()->status, 'a refused transition changes nothing');
            }

            $current = Ride::all()->keyBy('id');

            foreach ($rides as $r) {
                $r = $current[$r->id];

                if (isset($terminalSeen[$r->id])) {
                    $this->assertSame($terminalSeen[$r->id], $r->status, "terminal ride {$r->id} changed (seed {$seed}, step {$step})");
                } elseif ($r->statusEnum()->isTerminal()) {
                    $terminalSeen[$r->id] = $r->status;

                    // Keep the system busy: the passenger books again.
                    if (count($rides) < 80) {
                        $rides[] = $this->newRide(User::find($r->passenger_id), (bool) mt_rand(0, 1));
                    }
                }
            }

            $this->assertInvariants($seed, $step, $current);
        }

        $this->assertGreaterThan(60, $applied, 'the sequence must exercise real transitions, not only refusals');
    }

    private function newRide(User $passenger, bool $withPin): Ride
    {
        $ride = $this->makeRide(['status' => mt_rand(0, 3) === 0 ? 'scheduled' : 'requested', 'scheduled_for' => now()->addMinutes(20), 'estimated_fare' => 80], $passenger);

        if ($withPin) {
            $ride->forceFill(app(TripPin::class)->attributesFor($ride))->save();
        }

        return $ride;
    }

    private function assertInvariants(int $seed, int $step, Collection $all): void
    {
        $where = "(seed {$seed}, step {$step})";
        $holders = [];
        $activePassengers = [];

        foreach ($all as $ride) {
            $status = RideStatus::tryFrom($ride->status);
            $this->assertNotNull($status, "unknown status {$ride->status} {$where}");

            if ($status->holdsDriver()) {
                $this->assertNotNull($ride->driver_id, "ride {$ride->id} in {$ride->status} has no driver {$where}");
                $this->assertSame($ride->driver_id, $ride->active_driver_id, "guard column out of step on ride {$ride->id} {$where}");
                $this->assertArrayNotHasKey($ride->driver_id, $holders, "driver {$ride->driver_id} holds two rides {$where}");
                $holders[$ride->driver_id] = $ride->id;
            } else {
                $this->assertNull($ride->active_driver_id, "ride {$ride->id} in {$ride->status} still guards a driver {$where}");
            }

            if ($status->isActiveForPassenger()) {
                $this->assertArrayNotHasKey($ride->passenger_id, $activePassengers, "passenger {$ride->passenger_id} has two active rides {$where}");
                $activePassengers[$ride->passenger_id] = true;
            }
        }

        foreach (DriverAvailability::all() as $row) {
            $expected = $holders[$row->user_id] ?? null;
            $this->assertSame($expected, $row->current_ride_id, "availability of driver {$row->user_id} disagrees with the rides {$where}");
            $this->assertSame($expected !== null, $row->state === DriverAvailability::ON_TRIP, "driver {$row->user_id} state {$row->state} {$where}");
        }
    }
}
