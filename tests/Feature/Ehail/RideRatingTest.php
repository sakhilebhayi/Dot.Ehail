<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use App\Services\Ehail\DriverStats;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class RideRatingTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private function completedRide(?User $passenger = null, ?User $driver = null): Ride
    {
        $driver ??= $this->makeApprovedDriver();

        return $this->makeRide([
            'status' => 'completed', 'driver_id' => $driver->id,
            'final_fare' => 80, 'completed_at' => now(),
        ], $passenger);
    }

    // ─── Who may rate, and when ──────────────────────────────────────────

    public function test_the_passenger_can_rate_the_driver_after_completion(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);

        $this->actingAs($passenger)
            ->post(route('rides.ratings.store', $ride), ['rating' => 5, 'comment' => 'Smooth, careful drive'])
            ->assertRedirect(route('rides.show', $ride));

        $row = RideRating::firstOrFail();
        $this->assertSame($ride->id, $row->ride_id);
        $this->assertSame($passenger->id, $row->rated_by);
        $this->assertSame($ride->driver_id, $row->rated_user_id);
        $this->assertSame('driver', $row->rated_role);
        $this->assertSame(5, $row->rating);
        $this->assertSame('Smooth, careful drive', $row->comment);
    }

    public function test_the_driver_can_rate_the_passenger_after_completion(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->completedRide($passenger, $driver);

        $this->actingAs($driver)->post(route('rides.ratings.store', $ride), ['rating' => 4])->assertRedirect();

        $row = RideRating::firstOrFail();
        $this->assertSame($driver->id, $row->rated_by);
        $this->assertSame($passenger->id, $row->rated_user_id);
        $this->assertSame('passenger', $row->rated_role);
        $this->assertNull($row->comment);
    }

    public function test_each_party_rates_once_so_a_ride_can_carry_two_ratings(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->completedRide($passenger, $driver);

        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 5]);
        $this->actingAs($driver)->post(route('rides.ratings.store', $ride), ['rating' => 3]);

        $this->assertSame(2, RideRating::where('ride_id', $ride->id)->count());
    }

    public function test_a_second_rating_from_the_same_person_is_refused_and_changes_nothing(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 5, 'comment' => 'First and only']);

        $this->actingAs($passenger)
            ->post(route('rides.ratings.store', $ride), ['rating' => 1, 'comment' => 'Changed my mind'])
            ->assertSessionHasErrors(['rating' => 'You have already rated this ride.']);

        $this->assertSame(1, RideRating::count());
        $this->assertSame(5, RideRating::first()->rating);
    }

    public function test_the_database_itself_refuses_a_duplicate_rating_per_ride_and_rater(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);
        $row = ['ride_id' => $ride->id, 'rated_by' => $passenger->id, 'rated_user_id' => $ride->driver_id, 'rated_role' => 'driver', 'rating' => 4];
        DB::table('ride_ratings')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('ride_ratings')->insert($row);
    }

    public function test_a_ride_that_is_not_completed_cannot_be_rated(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();

        foreach (['requested', 'accepted', 'in_progress', 'cancelled', 'expired', 'needs_review'] as $status) {
            $ride = $this->makeRide(['status' => $status, 'driver_id' => $driver->id], $passenger);

            $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 5])->assertForbidden();
            $this->actingAs($driver)->post(route('rides.ratings.store', $ride), ['rating' => 5])->assertForbidden();
        }

        $this->assertSame(0, RideRating::count());
    }

    public function test_only_the_two_parties_can_rate(): void
    {
        $ride = $this->completedRide();
        $stranger = User::factory()->withPersonalTeam()->create();
        $otherDriver = $this->makeApprovedDriver();
        $operator = $this->makeOperator();

        foreach ([$stranger, $otherDriver, $operator] as $outsider) {
            $this->actingAs($outsider)->post(route('rides.ratings.store', $ride), ['rating' => 1])->assertForbidden();
        }

        $this->assertSame(0, RideRating::count());
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $ride = $this->completedRide();

        $this->post(route('rides.ratings.store', $ride), ['rating' => 5])->assertRedirect('/login');
    }

    // ─── Validation ──────────────────────────────────────────────────────

    public function test_the_rating_must_be_a_whole_number_from_one_to_five(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);

        foreach ([0, 6, -1, 3.5, 'five', '', null] as $bad) {
            $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => $bad])->assertSessionHasErrors('rating');
        }
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), [])->assertSessionHasErrors('rating');

        $this->assertSame(0, RideRating::count());
    }

    public function test_the_comment_may_be_at_most_500_characters(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);

        $this->actingAs($passenger)
            ->post(route('rides.ratings.store', $ride), ['rating' => 5, 'comment' => str_repeat('a', 501)])
            ->assertSessionHasErrors('comment');
        $this->assertSame(0, RideRating::count());

        $this->actingAs($passenger)
            ->post(route('rides.ratings.store', $ride), ['rating' => 5, 'comment' => str_repeat('a', 500)])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(500, strlen(RideRating::first()->comment));
    }

    public function test_the_database_refuses_a_rating_outside_one_to_five(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        foreach ([0, 6, 255] as $bad) {
            $ride = $this->completedRide($passenger);
            try {
                // A savepoint, so Postgres can carry on after the rejected insert.
                DB::transaction(fn () => DB::table('ride_ratings')->insert([
                    'ride_id' => $ride->id, 'rated_by' => $passenger->id,
                    'rated_user_id' => $ride->driver_id, 'rated_role' => 'driver', 'rating' => $bad,
                ]));
                $this->fail("a rating of {$bad} was stored");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, RideRating::count());

        // The bounds themselves are fine.
        foreach ([1, 5] as $ok) {
            $ride = $this->completedRide($passenger);
            DB::table('ride_ratings')->insert([
                'ride_id' => $ride->id, 'rated_by' => $passenger->id,
                'rated_user_id' => $ride->driver_id, 'rated_role' => 'driver', 'rating' => $ok,
            ]);
        }
        $this->assertSame(2, RideRating::count());
    }

    // ─── Driver aggregates ───────────────────────────────────────────────

    public function test_rating_a_driver_updates_their_average_and_total_rides(): void
    {
        $driver = $this->makeApprovedDriver();
        $first = User::factory()->withPersonalTeam()->create();
        $second = User::factory()->withPersonalTeam()->create();
        $rideOne = $this->completedRide($first, $driver);
        $rideTwo = $this->completedRide($second, $driver);
        $this->makeRide(['status' => 'cancelled', 'driver_id' => $driver->id]);

        $this->actingAs($first)->post(route('rides.ratings.store', $rideOne), ['rating' => 5]);
        $this->actingAs($second)->post(route('rides.ratings.store', $rideTwo), ['rating' => 2]);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame('3.50', $profile->rating);
        $this->assertSame(2, $profile->total_rides, 'only completed rides count');
    }

    public function test_a_drivers_rating_of_a_passenger_does_not_touch_the_drivers_own_average(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, $driver);

        $this->actingAs($driver)->post(route('rides.ratings.store', $ride), ['rating' => 1]);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame('0.00', $profile->rating);
    }

    public function test_a_driver_who_also_rides_is_rated_as_a_driver_only_for_their_driving(): void
    {
        $driverRider = $this->makeApprovedDriver();
        $otherDriver = $this->makeApprovedDriver();
        // driverRider is the PASSENGER here; otherDriver drives them and rates them 1.
        $asPassenger = $this->completedRide($driverRider, $otherDriver);
        $this->actingAs($otherDriver)->post(route('rides.ratings.store', $asPassenger), ['rating' => 1]);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driverRider->id)->firstOrFail();
        $this->assertSame('0.00', $profile->rating);
    }

    public function test_recomputing_driver_stats_is_idempotent(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, $driver);
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 4]);

        $stats = app(DriverStats::class);
        $stats->refresh($driver->id);
        $stats->refresh($driver->id);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame('4.00', $profile->rating);
        $this->assertSame(1, $profile->total_rides);
    }

    public function test_recomputing_repairs_a_drifted_profile(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, $driver);
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 4]);
        DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->update(['rating' => 1.11, 'total_rides' => 99]);

        app(DriverStats::class)->refresh($driver->id);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame('4.00', $profile->rating);
        $this->assertSame(1, $profile->total_rides);
    }

    public function test_completing_a_ride_bumps_the_drivers_total_rides(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));
        foreach (['en_route', 'arrived', 'in_progress', 'completed'] as $stage) {
            $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => $stage]);
        }

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame(1, $profile->total_rides);
    }

    public function test_the_rating_and_the_aggregate_are_one_transaction(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, $driver);

        $this->app->instance(DriverStats::class, new class extends DriverStats
        {
            public function refresh(int $driverUserId): void
            {
                throw new RuntimeException('aggregate failed');
            }
        });

        try {
            $this->withoutExceptionHandling()->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 5]);
            $this->fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('aggregate failed', $e->getMessage());
        }

        $this->assertSame(0, RideRating::count(), 'the rating must roll back with the aggregate');
    }

    // ─── Visibility ──────────────────────────────────────────────────────

    public function test_the_ride_page_offers_the_rating_form_only_to_a_party_who_has_not_rated(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->completedRide($passenger, $driver);

        $this->actingAs($passenger)->get(route('rides.show', $ride))->assertOk()->assertSee('Rate your driver');
        $this->actingAs($driver)->get(route('rides.show', $ride))->assertOk()->assertSee('Rate your passenger');

        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 5, 'comment' => 'Distinctive praise for the careful driver']);

        $this->actingAs($passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertDontSee('Rate your driver')
            ->assertSee('Your rating')
            ->assertSee('Distinctive praise for the careful driver');
    }

    public function test_a_party_does_not_see_the_other_partys_rating_of_them(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->completedRide($passenger, $driver);
        $this->actingAs($driver)->post(route('rides.ratings.store', $ride), ['rating' => 2, 'comment' => 'Private remark about the passenger']);

        $this->actingAs($passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertDontSee('Private remark about the passenger');
    }

    public function test_no_rating_form_before_the_ride_is_completed(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id], $passenger);

        $this->actingAs($passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertDontSee('Rate your driver');
    }

    public function test_the_driver_profile_shows_the_aggregate_and_recent_comments_without_rater_names_to_the_driver(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Nokuthula Distinctive Rider']);
        $ride = $this->completedRide($passenger, $driver);
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 4, 'comment' => 'Arrived early and kept the car spotless']);
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();

        $this->actingAs($driver)->get(route('drivers.show', $profile))
            ->assertOk()
            ->assertSee('data-figure="rating-average">4.00<', false)
            ->assertSee('data-figure="rating-count">1<', false)
            ->assertSee('Arrived early and kept the car spotless')
            ->assertDontSee('Nokuthula Distinctive Rider');
    }

    public function test_an_operator_and_the_fleet_owner_can_see_who_left_a_rating(): void
    {
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Nokuthula Distinctive Rider']);
        $ride = $this->completedRide($passenger, $driver);
        $this->actingAs($passenger)->post(route('rides.ratings.store', $ride), ['rating' => 4, 'comment' => 'Arrived early and kept the car spotless']);
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->with('fleet')->firstOrFail();

        $this->actingAs($this->makeOperator())->get(route('drivers.show', $profile))->assertOk()->assertSee('Nokuthula Distinctive Rider');
        $this->actingAs($profile->fleet->owner)->get(route('drivers.show', $profile))->assertOk()->assertSee('Nokuthula Distinctive Rider');
    }

    public function test_a_driver_with_no_ratings_says_so_instead_of_showing_zero_stars(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();

        $this->actingAs($driver)->get(route('drivers.show', $profile))
            ->assertOk()
            ->assertSee('No ratings yet');
    }

    public function test_the_passenger_sees_the_drivers_aggregate_on_the_ride_page_but_no_comments(): void
    {
        $driver = $this->makeApprovedDriver();
        $earlier = User::factory()->withPersonalTeam()->create();
        $earlierRide = $this->completedRide($earlier, $driver);
        $this->actingAs($earlier)->post(route('rides.ratings.store', $earlierRide), ['rating' => 5, 'comment' => 'Comment from an earlier rider only']);

        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'accepted', 'driver_id' => $driver->id], $passenger);

        $this->actingAs($passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertSee('data-figure="driver-rating">5.00<', false)
            ->assertDontSee('Comment from an earlier rider only');
    }
}
