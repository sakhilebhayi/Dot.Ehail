<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Incentives\IncentiveEngine;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\IncentiveProgram;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class IncentivesTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday noon, so the week window is Mon 2026-11-09 .. Sun 2026-11-15.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
        $this->driver = $this->makeApprovedDriver();
    }

    private function program(array $attributes = []): IncentiveProgram
    {
        return IncentiveProgram::create(array_merge([
            'name' => 'Weekly 3 trips', 'kind' => 'trip_count', 'rules' => ['trips' => 3], 'reward_minor' => 15000,
            'currency' => 'ZAR', 'window' => 'week', 'starts_at' => '2026-11-01 00:00:00', 'status' => 'active',
        ], $attributes));
    }

    private function trips(int $count, ?User $driver = null, array $attributes = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeRide(array_merge(['status' => 'completed', 'driver_id' => ($driver ?? $this->driver)->id, 'final_fare' => 50, 'completed_at' => now()->subMinutes(10 + $i)], $attributes));
        }
    }

    public function test_progress_is_shown_but_nothing_is_earned_until_the_rules_are_met(): void
    {
        $program = $this->program();
        $this->trips(2);

        app(IncentiveEngine::class)->evaluate($program);

        $progress = IncentiveProgress::sole();
        $this->assertSame('in_progress', $progress->status);
        $this->assertSame(66, $progress->percent);
        $this->assertSame(2, $progress->progress['trips']);
        $this->assertSame(0, DriverEarning::count());
        $this->actingAs($this->driver)->getJson(route('money.driver.incentives'))->assertOk()
            ->assertJsonPath('programs.0.state', 'in_progress')->assertJsonPath('programs.0.progress.trips', 2);

        $this->trips(1);
        $this->assertSame(1, app(IncentiveEngine::class)->evaluate($program)['awarded']);
        app(IncentiveEngine::class)->evaluate($program); // again: nothing new

        $earning = DriverEarning::sole();
        $this->assertSame(DriverEarning::KIND_INCENTIVE, $earning->kind);
        $this->assertSame(15000, $earning->net_minor);
        $this->assertSame(15000, app(Ledger::class)->balance(Account::driverPayable($this->driver->id), 'ZAR'));
        $this->assertSame(15000, app(Ledger::class)->balance(Account::INCENTIVES_EXPENSE, 'ZAR'));
        $this->assertSame(15000, $program->fresh()->awarded_minor);
        $this->actingAs($this->driver)->getJson(route('money.driver.incentives'))->assertJsonPath('programs.0.state', 'earned');
    }

    public function test_trips_outside_the_window_do_not_count_and_a_missed_week_closes_as_not_met(): void
    {
        $program = $this->program();
        $this->trips(5, null, ['completed_at' => CarbonImmutable::parse('2026-11-08 23:59:00')]); // previous Sunday
        $this->trips(2);

        // Monday of the next week: the closed window is evaluated one last time.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 00:30:00'));
        app(IncentiveEngine::class)->evaluate($program);

        $closed = IncentiveProgress::where('window_start', CarbonImmutable::parse('2026-11-09 00:00:00'))->sole();
        $this->assertSame('not_met', $closed->status);
        $this->assertSame(2, $closed->progress['trips']);
        $this->assertSame(0, DriverEarning::count());
    }

    public function test_the_budget_cap_stops_awards_and_a_paused_programme_awards_nothing(): void
    {
        $program = $this->program(['budget_minor' => 15000]);
        $second = $this->makeApprovedDriver();
        $this->trips(3);
        $this->trips(3, $second);

        app(IncentiveEngine::class)->evaluate($program);

        $this->assertSame(1, DriverEarning::count());
        $this->assertSame(['budget_exhausted', 'earned'], IncentiveProgress::orderBy('status')->pluck('status')->all());

        $paused = $this->program(['name' => 'Paused', 'status' => 'paused']);
        $this->assertSame(['evaluated' => 0, 'awarded' => 0], app(IncentiveEngine::class)->evaluate($paused));
    }

    public function test_area_programmes_count_only_pickups_inside_the_polygon(): void
    {
        // Rough box around Cape Town CBD.
        $program = $this->program(['kind' => 'area', 'rules' => ['trips' => 2, 'polygon' => [[-33.90, 18.40], [-33.90, 18.44], [-33.94, 18.44], [-33.94, 18.40]]]]);
        $this->trips(1, null, ['pickup_lat' => -33.92, 'pickup_lng' => 18.42]);
        $this->trips(3, null, ['pickup_lat' => -26.20, 'pickup_lng' => 28.04]); // Johannesburg

        app(IncentiveEngine::class)->evaluate($program);
        $this->assertSame(1, IncentiveProgress::sole()->progress['trips']);
        $this->assertSame(0, DriverEarning::count());

        $this->trips(1, null, ['pickup_lat' => -33.91, 'pickup_lng' => 18.43]);
        app(IncentiveEngine::class)->evaluate($program);
        $this->assertSame(1, DriverEarning::count());
    }

    public function test_online_hours_and_referrals_are_unmeasurable_until_their_data_exists(): void
    {
        $hours = $this->program(['kind' => 'online_hours', 'rules' => ['hours' => 10, 'min_acceptance' => 0.8]]);
        $this->trips(20);

        $this->assertSame(0, app(IncentiveEngine::class)->evaluate($hours)['awarded']);
        $this->actingAs($this->driver)->getJson(route('money.driver.incentives'))->assertJsonPath('programs.0.state', 'unmeasurable');
        $this->assertSame(0, DriverEarning::count());
    }

    public function test_a_milestone_is_awarded_once(): void
    {
        $program = $this->program(['kind' => 'milestone', 'window' => 'program', 'rules' => ['lifetime_trips' => 3]]);
        $this->trips(3);

        app(IncentiveEngine::class)->evaluate($program);
        $this->travel(2)->days();
        $this->trips(2);
        app(IncentiveEngine::class)->evaluate($program);

        $this->assertSame(1, DriverEarning::count());
    }

    public function test_operators_create_and_pause_programmes_and_others_cannot(): void
    {
        $operator = $this->makeOperator();
        $payload = ['name' => 'Weekend', 'kind' => 'trip_count', 'window' => 'week', 'reward_minor' => 10000, 'starts_at' => '2026-11-12', 'rules' => ['trips' => 10]];

        $this->actingAs($this->driver)->postJson(route('money.ops.incentives.store'), $payload)->assertForbidden();
        $id = $this->actingAs($operator)->postJson(route('money.ops.incentives.store'), $payload)->assertCreated()->json('program.id');
        $this->actingAs($operator)->postJson(route('money.ops.incentives.store'), ['kind' => 'area'] + $payload)->assertUnprocessable();

        $this->actingAs($operator)->postJson(route('money.ops.incentives.status', $id), ['status' => 'active'])->assertOk();
        $this->actingAs($operator)->postJson(route('money.ops.incentives.status', $id), ['status' => 'paused'])->assertOk()->assertJsonPath('program.status', 'paused');
    }

    public function test_a_minimum_rating_rule_is_checked(): void
    {
        $program = $this->program(['rules' => ['trips' => 1, 'min_rating' => 4.8]]);
        DriverProfile::withoutGlobalScope('user')->where('user_id', $this->driver->id)->update(['rating' => 4.5]);
        $this->trips(2);

        app(IncentiveEngine::class)->evaluate($program);

        $this->assertSame(0, DriverEarning::count());
        $this->assertSame(4.5, IncentiveProgress::sole()->progress['rating']);
        $this->assertNotNull(Ride::first());
    }
}
