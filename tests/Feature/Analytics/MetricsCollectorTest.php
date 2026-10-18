<?php

namespace Tests\Feature\Analytics;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\CompanyDirectory;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What Dot.Ehail measures for each company, and above all what it must never include: another
 * fleet's rides or drivers, unapproved fleets, unlinked owners, anything outside the UTC window,
 * and numbers that cannot be attributed to a fleet at all.
 *
 * "Now" is 2026-11-05 00:15 UTC, so taken_at is 2026-11-05T00:00Z and the 7 day window is
 * [2026-10-29 00:00Z, 2026-11-05 00:00Z).
 */
class MetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const OPERATOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /** The only metric keys Dot.Analytics' config/metrics.php accepts for `ehail` (PR #15). */
    private const CONTRACT = ['rides_requested_7d', 'rides_completed_7d', 'rides_expired_7d', 'drivers_online', 'avg_rating'];

    private Carbon $takenAt;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        $this->takenAt = Carbon::parse('2026-11-05 00:00:00', 'UTC');
        config(['analytics.company_uuid' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(?string $uuid): User
    {
        $user = User::factory()->withPersonalTeam()->create();

        if ($uuid !== null) {
            $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $user->fresh();
    }

    /** Another person whose current team is $of's current team: the same company. */
    private function colleague(User $of): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->forceFill(['current_team_id' => $of->current_team_id])->save();

        return $user->fresh();
    }

    private function fleet(User $owner, string $status = 'approved'): Fleet
    {
        return Fleet::factory()->create(['owner_user_id' => $owner->id, 'status' => $status]);
    }

    private function driver(Fleet $fleet, string $status = 'approved', bool $online = true): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->create(['user_id' => $user->id, 'fleet_id' => $fleet->id, 'status' => $status, 'is_online' => $online]);

        return $user;
    }

    /** A time given in UTC, as the wall-clock value the database holds in the app timezone. */
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC')->timezone(config('app.timezone'));
    }

    private function ride(array $attributes = []): Ride
    {
        $passenger = User::factory()->create();
        $created = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $ride = Ride::create($attributes + [
            'passenger_id' => $passenger->id, 'pickup_address' => '10 Kloof Street', 'dropoff_address' => '20 Long Street',
            'status' => 'requested', 'vehicle_type' => 'standard', 'estimated_fare' => 80,
        ]);

        if ($created !== null) {
            $ride->forceFill(['created_at' => $created])->save();
        }

        return $ride;
    }

    private function completedRide(User $driver, string $completedUtc): Ride
    {
        return $this->ride(['driver_id' => $driver->id, 'status' => 'completed', 'completed_at' => $this->at($completedUtc), 'created_at' => $this->at('2026-10-01 00:00:00')]);
    }

    private function rate(User $driver, int $stars, string $role = RideRating::ROLE_DRIVER): RideRating
    {
        $ride = $this->ride(['driver_id' => $driver->id, 'status' => 'completed', 'completed_at' => $this->at('2026-10-01 00:00:00')]);

        return RideRating::create(['ride_id' => $ride->id, 'rated_by' => $ride->passenger_id, 'rated_user_id' => $driver->id, 'rated_role' => $role, 'rating' => $stars]);
    }

    /** @return array<string, int|float> */
    private function forFleets(Fleet ...$fleets): array
    {
        return app(MetricsCollector::class)->collectForFleets(array_map(fn (Fleet $f) => $f->id, $fleets), $this->takenAt);
    }

    // ---- fleet snapshot: the numbers ---------------------------------------------------------

    public function test_a_fleet_with_nothing_reports_true_zeros_and_no_rating(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));

        $this->assertSame(['rides_completed_7d' => 0, 'drivers_online' => 0], $this->forFleets($fleet));
    }

    public function test_completed_counts_rides_its_drivers_completed_inside_the_utc_window(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $driver = $this->driver($fleet);
        $this->completedRide($driver, '2026-10-29 00:00:00');   // exactly the window start: in
        $this->completedRide($driver, '2026-11-04 23:59:59');   // last second: in
        $this->completedRide($driver, '2026-11-02 12:00:00');   // in
        $this->completedRide($driver, '2026-10-28 23:59:59');   // before the window
        $this->completedRide($driver, '2026-11-05 00:00:00');   // exactly taken_at: the next window's
        $this->ride(['driver_id' => $driver->id, 'status' => 'cancelled', 'cancelled_at' => $this->at('2026-11-02 12:00:00')]);
        $this->ride(['driver_id' => $driver->id, 'status' => 'in_progress', 'started_at' => $this->at('2026-11-02 12:00:00')]);
        $this->ride(['driver_id' => $driver->id, 'status' => 'needs_review', 'completed_at' => $this->at('2026-11-02 12:00:00')]);
        $this->ride(['driver_id' => $driver->id, 'status' => 'requested', 'completed_at' => $this->at('2026-11-02 12:00:00')]);

        $this->assertSame(3, $this->forFleets($fleet)['rides_completed_7d']);
    }

    public function test_drivers_online_counts_approved_online_drivers_only(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $this->driver($fleet);
        $this->driver($fleet);
        $this->driver($fleet, 'approved', false);
        $this->driver($fleet, 'pending', true);
        $this->driver($fleet, 'suspended', true);
        $this->driver($fleet, 'rejected', true);

        $this->assertSame(2, $this->forFleets($fleet)['drivers_online']);
    }

    public function test_avg_rating_is_the_mean_of_passenger_ratings_of_its_drivers_rounded_to_two_decimals(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $one = $this->driver($fleet);
        $two = $this->driver($fleet);
        foreach ([5, 4, 4] as $stars) {
            $this->rate($one, $stars);
        }
        $this->rate($two, 3);
        $this->rate($one, 1, RideRating::ROLE_PASSENGER);   // a driver rating a passenger is not a driver rating
        $this->rate($this->driver($fleet, 'approved', false), 2, RideRating::ROLE_PASSENGER);

        $metrics = $this->forFleets($fleet);

        $this->assertSame(4.0, $metrics['avg_rating'], '(5+4+4+3)/4 = 4');
        $this->rate($two, 5);
        $this->assertSame(4.2, $this->forFleets($fleet)['avg_rating'], '(5+4+4+3+5)/5 = 4.2');
        $this->rate($two, 4);
        $this->assertSame(4.17, $this->forFleets($fleet)['avg_rating'], '25/6 = 4.1666...');
    }

    public function test_avg_rating_is_left_out_not_zero_when_there_are_no_driver_ratings(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $driver = $this->driver($fleet);
        $this->rate($driver, 5, RideRating::ROLE_PASSENGER);

        $this->assertArrayNotHasKey('avg_rating', $this->forFleets($fleet));
    }

    public function test_a_fleet_snapshot_never_carries_what_cannot_be_attributed_to_a_fleet(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $driver = $this->driver($fleet);
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-02 10:00:00')]);
        $this->ride(['status' => 'expired', 'expired_at' => $this->at('2026-11-02 10:00:00'), 'created_at' => $this->at('2026-11-02 09:00:00')]);
        $this->completedRide($driver, '2026-11-02 10:00:00');
        $this->rate($driver, 5);

        $keys = array_keys($this->forFleets($fleet));

        $this->assertSame(['rides_completed_7d', 'drivers_online', 'avg_rating'], $keys);
        $this->assertSame(MetricsCollector::FLEET_KEYS, $keys);
        $this->assertNotContains('rides_requested_7d', $keys, 'a request has no driver, so no fleet');
        $this->assertNotContains('rides_expired_7d', $keys, 'an expired ride has had its driver cleared');
    }

    public function test_all_fleets_of_one_company_are_combined(): void
    {
        $owner = $this->owner(self::COMPANY_A);
        $first = $this->fleet($owner);
        $second = $this->fleet($owner);
        $this->completedRide($this->driver($first), '2026-11-02 10:00:00');
        $this->completedRide($this->driver($second), '2026-11-02 10:00:00');

        $metrics = $this->forFleets($first, $second);

        $this->assertSame(2, $metrics['rides_completed_7d']);
        $this->assertSame(2, $metrics['drivers_online']);
    }

    public function test_only_whole_numbers_and_one_rating_are_produced(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $driver = $this->driver($fleet);
        $this->completedRide($driver, '2026-11-02 10:00:00');
        $this->rate($driver, 4);

        foreach ($this->forFleets($fleet) as $key => $value) {
            $key === 'avg_rating' ? $this->assertIsFloat($value) : $this->assertIsInt($value, $key);
        }
    }

    // ---- fleet scoping: never another company's rows -------------------------------------------

    public function test_each_fleet_gets_only_its_own_drivers_rides_and_ratings(): void
    {
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $fleetB = $this->fleet($this->owner(self::COMPANY_B));
        $a = $this->driver($fleetA);
        $b1 = $this->driver($fleetB);
        $b2 = $this->driver($fleetB);
        $this->driver($fleetB, 'approved', false);
        $this->completedRide($a, '2026-11-02 10:00:00');
        foreach (range(1, 3) as $i) {
            $this->completedRide($b1, '2026-11-0'.$i.' 10:00:00');
        }
        $this->completedRide($b2, '2026-11-02 11:00:00');
        $this->rate($a, 5);
        $this->rate($b1, 1);
        $this->rate($b2, 2);

        $this->assertSame(['rides_completed_7d' => 1, 'drivers_online' => 1, 'avg_rating' => 5.0], $this->forFleets($fleetA));
        $this->assertSame(['rides_completed_7d' => 4, 'drivers_online' => 2, 'avg_rating' => 1.5], $this->forFleets($fleetB));
    }

    public function test_a_fleets_figures_do_not_change_when_another_fleet_grows(): void
    {
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $fleetB = $this->fleet($this->owner(self::COMPANY_B));
        $a = $this->driver($fleetA);
        $this->completedRide($a, '2026-11-02 10:00:00');
        $this->rate($a, 4);
        $before = $this->forFleets($fleetA);

        $b = $this->driver($fleetB);
        $this->completedRide($b, '2026-11-02 10:00:00');
        $this->rate($b, 1);
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-02 10:00:00')]);

        $this->assertSame($before, $this->forFleets($fleetA));
    }

    public function test_an_empty_fleet_list_is_not_everything(): void
    {
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $this->completedRide($this->driver($fleet), '2026-11-02 10:00:00');

        $this->assertSame([], app(MetricsCollector::class)->collectForFleets([], $this->takenAt));
    }

    public function test_a_driver_who_never_belonged_to_the_fleet_is_not_counted_even_if_the_passenger_was_from_it(): void
    {
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $fleetB = $this->fleet($this->owner(self::COMPANY_B));
        $b = $this->driver($fleetB);
        $a = $this->driver($fleetA);
        // Company A's driver is the passenger on B's ride.
        $ride = $this->ride(['driver_id' => $b->id, 'status' => 'completed', 'completed_at' => $this->at('2026-11-02 10:00:00'), 'passenger_id' => $a->id]);

        $this->assertSame(0, $this->forFleets($fleetA)['rides_completed_7d']);
        $this->assertSame(1, $this->forFleets($fleetB)['rides_completed_7d']);
        $this->assertSame($a->id, $ride->passenger_id);
    }

    // ---- UTC windows, whatever the app timezone is --------------------------------------------

    public function test_the_window_is_the_utc_window_even_when_the_app_runs_in_another_timezone(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $fleet = $this->fleet($this->owner(self::COMPANY_A));
        $driver = $this->driver($fleet);
        $this->completedRide($driver, '2026-11-04 22:30:00');   // 00:30 on 5 Nov in Johannesburg, still before taken_at in UTC: in
        $this->completedRide($driver, '2026-11-04 22:59:59');   // in
        $this->completedRide($driver, '2026-11-05 00:30:00');   // 02:30 local, after taken_at: out
        $this->completedRide($driver, '2026-10-28 23:30:00');   // 01:30 on 29 Oct local, before the window in UTC: out
        $this->completedRide($driver, '2026-10-29 00:30:00');   // in

        $this->assertSame(3, $this->forFleets($fleet)['rides_completed_7d']);
    }

    // ---- who counts as a company --------------------------------------------------------------

    public function test_snapshots_give_each_company_with_an_approved_fleet_one_fleet_snapshot(): void
    {
        $a1 = $this->owner(self::COMPANY_A);
        $a2 = $this->colleague($a1);
        $b = $this->owner(self::COMPANY_B);
        $fleetA1 = $this->fleet($a1);
        $fleetA2 = $this->fleet($a2);
        $fleetB = $this->fleet($b);
        $this->driver($fleetA1);
        $this->driver($fleetA2);
        $this->driver($fleetB);
        $this->driver($fleetB);

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($snapshots));
        $this->assertSame(2, $snapshots[self::COMPANY_A]['drivers_online'], 'both fleets of the one company');
        $this->assertSame(2, $snapshots[self::COMPANY_B]['drivers_online']);
    }

    public function test_pending_fleets_and_unlinked_owners_publish_nothing(): void
    {
        $pending = $this->fleet($this->owner(self::COMPANY_A), 'pending');
        $unlinked = $this->fleet($this->owner(null));
        $this->driver($pending);
        $this->driver($unlinked);
        $this->completedRide($this->driver($unlinked), '2026-11-02 10:00:00');

        $this->assertSame([], app(MetricsCollector::class)->snapshots($this->takenAt));
    }

    public function test_a_pending_fleet_of_an_approved_owner_adds_nothing_to_the_company(): void
    {
        $owner = $this->owner(self::COMPANY_A);
        $approved = $this->fleet($owner);
        $pending = $this->fleet($owner, 'pending');
        $this->completedRide($this->driver($approved), '2026-11-02 10:00:00');
        $this->completedRide($this->driver($pending), '2026-11-02 10:00:00');
        $this->driver($pending);

        $snapshot = app(MetricsCollector::class)->snapshots($this->takenAt)[self::COMPANY_A];

        $this->assertSame(['rides_completed_7d' => 1, 'drivers_online' => 1], $snapshot);
    }

    public function test_the_driver_privacy_scope_of_whoever_is_logged_in_never_changes_the_numbers(): void
    {
        config(['analytics.company_uuid' => self::OPERATOR]);
        $owner = $this->owner(self::COMPANY_A);
        $fleet = $this->fleet($owner);
        $this->driver($fleet);
        $this->driver($this->fleet($this->owner(self::COMPANY_B)));
        $stranger = User::factory()->create();
        $before = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->actingAs($stranger);
        $asStranger = app(MetricsCollector::class)->snapshots($this->takenAt);
        $this->actingAs($owner);
        $asOwner = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame(1, $before[self::COMPANY_A]['drivers_online']);
        $this->assertSame(2, $before[self::OPERATOR]['drivers_online']);
        $this->assertSame($before, $asStranger, 'DriverProfile\'s user scope must not leak into a scheduled aggregate');
        $this->assertSame($before, $asOwner);
    }

    public function test_snapshots_can_be_limited_to_one_company(): void
    {
        $this->driver($this->fleet($this->owner(self::COMPANY_A)));
        $this->driver($this->fleet($this->owner(self::COMPANY_B)));

        $this->assertSame([self::COMPANY_B], array_keys(app(MetricsCollector::class)->snapshots($this->takenAt, strtoupper(self::COMPANY_B))));
        $this->assertSame([], app(MetricsCollector::class)->snapshots($this->takenAt, self::OPERATOR));
    }

    public function test_the_company_is_the_owners_current_team_then_their_personal_team_and_nothing_is_switched(): void
    {
        $colleague = $this->owner(self::COMPANY_A);
        $both = User::factory()->withPersonalTeam()->create();
        $both->personalTeam()->forceFill(['infodot_team_uuid' => self::COMPANY_B])->save();
        $both->forceFill(['current_team_id' => $colleague->current_team_id])->save();
        $fallback = User::factory()->withPersonalTeam()->create();
        $fallback->personalTeam()->forceFill(['infodot_team_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'])->save();
        $side = Team::forceCreate(['user_id' => $fallback->id, 'name' => 'Side project', 'personal_team' => false]);
        $fallback->forceFill(['current_team_id' => $side->id])->save();
        $none = User::factory()->withPersonalTeam()->create();
        $none->personalTeam()->forceFill(['infodot_team_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])->save();
        $none->forceFill(['current_team_id' => null])->save();
        $blank = $this->owner('');

        $groups = app(CompanyDirectory::class)->group([$colleague->id, $both->id, $fallback->id, $none->id, $blank->id]);

        $this->assertSame([
            self::COMPANY_A => [$colleague->id, $both->id],
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd' => [$fallback->id],
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' => [$none->id],
        ], $groups);
        $this->assertSame($side->id, $fallback->fresh()->current_team_id, 'resolving a company must not switch the user\'s team');
        $this->assertNull($none->fresh()->current_team_id);
    }

    // ---- platform snapshot: the operating company only ------------------------------------------

    public function test_without_a_configured_operating_company_there_is_no_platform_snapshot(): void
    {
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-02 10:00:00')]);

        foreach ([null, '', '   ', 'not-a-uuid', 'cccccccc-cccc-4ccc-8ccc'] as $bad) {
            config(['analytics.company_uuid' => $bad]);

            $this->assertSame([], app(MetricsCollector::class)->snapshots($this->takenAt), var_export($bad, true));
            $this->assertNull(app(MetricsCollector::class)->operatorCompany());
        }
    }

    public function test_the_operating_company_gets_the_platform_wide_numbers_with_all_five_keys(): void
    {
        config(['analytics.company_uuid' => strtoupper(self::OPERATOR)]);
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $fleetB = $this->fleet($this->owner(self::COMPANY_B));
        $a = $this->driver($fleetA);
        $b = $this->driver($fleetB);
        $this->driver($fleetB, 'pending', true);
        $this->driver($fleetB, 'approved', false);
        $this->completedRide($a, '2026-11-02 10:00:00');
        $this->completedRide($b, '2026-11-02 10:00:00');
        $this->completedRide($b, '2026-11-03 10:00:00');
        $this->rate($a, 5);
        $this->rate($b, 4);
        // Requested in the window by created_at: 4 (one requeued, whose requested_at was reset afterwards).
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-02 10:00:00')]);
        $this->ride(['status' => 'expired', 'expired_at' => $this->at('2026-11-03 10:00:00'), 'created_at' => $this->at('2026-11-03 09:50:00')]);
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-10-29 00:00:00')]);
        $this->ride(['status' => 'requested', 'requested_at' => $this->at('2026-11-04 12:00:00'), 'created_at' => $this->at('2026-11-02 08:00:00'), 'reassignment_count' => 1]);
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-10-28 23:59:59')]);   // before
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-05 00:00:00')]);   // taken_at itself: next window
        // Expired in the window: 1.
        $this->ride(['status' => 'expired', 'expired_at' => $this->at('2026-10-28 23:59:59'), 'created_at' => $this->at('2026-10-28 23:00:00')]);
        $this->ride(['status' => 'expired', 'expired_at' => $this->at('2026-11-05 00:00:00'), 'created_at' => $this->at('2026-11-04 23:00:00')]);
        $this->ride(['status' => 'cancelled', 'expired_at' => $this->at('2026-11-03 10:00:00'), 'created_at' => $this->at('2026-11-03 09:00:00')]);

        $platform = app(MetricsCollector::class)->snapshots($this->takenAt, self::OPERATOR)[self::OPERATOR];

        $this->assertSame(self::CONTRACT, array_keys($platform));
        $this->assertSame([
            'rides_requested_7d' => 4 + 1 + 1,   // the four above, the expired one created in the window, and the cancelled one
            'rides_completed_7d' => 3,
            'rides_expired_7d' => 1,
            'drivers_online' => 2,
            'avg_rating' => 4.5,
        ], $platform);
    }

    public function test_platform_requests_count_each_ride_once_by_its_original_request_time(): void
    {
        config(['analytics.company_uuid' => self::OPERATOR]);
        // Handed back and re-queued twice: still one request, dated by created_at, not by the last requested_at.
        $this->ride(['status' => 'requested', 'requested_at' => $this->at('2026-11-04 20:00:00'), 'created_at' => $this->at('2026-10-20 10:00:00'), 'reassignment_count' => 2]);

        $this->assertSame(0, app(MetricsCollector::class)->collectPlatform($this->takenAt)['rides_requested_7d']);
    }

    public function test_an_empty_platform_still_reports_true_zeros_without_a_rating(): void
    {
        config(['analytics.company_uuid' => self::OPERATOR]);

        $this->assertSame(
            [self::OPERATOR => ['rides_requested_7d' => 0, 'rides_completed_7d' => 0, 'rides_expired_7d' => 0, 'drivers_online' => 0]],
            app(MetricsCollector::class)->snapshots($this->takenAt),
        );
    }

    public function test_the_operating_company_and_fleet_companies_are_separate_snapshots_and_the_fleets_stay_scoped(): void
    {
        config(['analytics.company_uuid' => self::OPERATOR]);
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $this->completedRide($this->driver($fleetA), '2026-11-02 10:00:00');
        $this->completedRide($this->driver($this->fleet($this->owner(self::COMPANY_B))), '2026-11-02 10:00:00');
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-02 10:00:00')]);

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_A, self::COMPANY_B, self::OPERATOR], array_keys($snapshots));
        $this->assertSame(['rides_completed_7d' => 1, 'drivers_online' => 1], $snapshots[self::COMPANY_A]);
        $this->assertSame(2, $snapshots[self::OPERATOR]['rides_completed_7d'], 'the operator sees the whole platform');
        $this->assertSame(1, $snapshots[self::OPERATOR]['rides_requested_7d']);
        $this->assertArrayNotHasKey('rides_requested_7d', $snapshots[self::COMPANY_A]);
    }

    public function test_when_the_operating_company_also_owns_a_fleet_it_gets_the_one_platform_snapshot(): void
    {
        config(['analytics.company_uuid' => self::COMPANY_A]);
        $fleetA = $this->fleet($this->owner(self::COMPANY_A));
        $this->completedRide($this->driver($fleetA), '2026-11-02 10:00:00');
        $this->completedRide($this->driver($this->fleet($this->owner(self::COMPANY_B))), '2026-11-02 10:00:00');

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($snapshots), 'one snapshot per company, never two for the same identity');
        $this->assertSame(self::CONTRACT[0], array_keys($snapshots[self::COMPANY_A])[0]);
        $this->assertSame(2, $snapshots[self::COMPANY_A]['rides_completed_7d']);
        $this->assertSame(1, $snapshots[self::COMPANY_B]['rides_completed_7d'], 'the other fleet owner still only sees its own');
    }

    public function test_the_platform_window_is_the_utc_window_in_any_app_timezone(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg', 'analytics.company_uuid' => self::OPERATOR]);
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-04 22:30:00')]);   // in
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-04 22:59:59')]);   // in
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-11-05 00:30:00')]);   // out
        $this->ride(['status' => 'requested', 'created_at' => $this->at('2026-10-28 23:30:00')]);   // out

        $this->assertSame(2, app(MetricsCollector::class)->collectPlatform($this->takenAt)['rides_requested_7d']);
    }
}
