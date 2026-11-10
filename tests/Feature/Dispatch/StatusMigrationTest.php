<?php

namespace Tests\Feature\Dispatch;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The state machine v2 data migration on rows written by the pre-R-46 code.
 */
class StatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function rollBackToBeforeDispatch(): void
    {
        $steps = DB::table('migrations')->where('migration', '>=', '2026_11_10_400000_add_dispatch_tables_and_ride_columns')->count();
        $this->artisan('migrate:rollback', ['--step' => $steps])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('ride_events'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legacyRide(array $attributes): int
    {
        return DB::table('rides')->insertGetId(array_merge([
            'pickup_address' => 'A', 'dropoff_address' => 'B', 'vehicle_type' => 'standard',
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_pre_v2_statuses_are_renamed_and_every_row_is_kept(): void
    {
        $passenger = User::factory()->create();
        $other = User::factory()->create();
        $operator = User::factory()->create();
        $driver = User::factory()->create();
        $this->rollBackToBeforeDispatch();

        $ids = [
            'accepted' => $this->legacyRide(['passenger_id' => $passenger->id, 'status' => 'accepted', 'driver_id' => $driver->id]),
            'en_route' => $this->legacyRide(['passenger_id' => $other->id, 'status' => 'en_route']),
            'arrived' => $this->legacyRide(['passenger_id' => $operator->id, 'status' => 'arrived']),
            'by_passenger' => $this->legacyRide(['passenger_id' => $passenger->id, 'status' => 'cancelled', 'cancelled_by' => $passenger->id]),
            'by_operator' => $this->legacyRide(['passenger_id' => $passenger->id, 'status' => 'cancelled', 'cancelled_by' => $operator->id]),
            'unknown_canceller' => $this->legacyRide(['passenger_id' => $passenger->id, 'status' => 'cancelled']),
            'completed' => $this->legacyRide(['passenger_id' => $passenger->id, 'status' => 'completed', 'final_fare' => 90]),
            'requested' => $this->legacyRide(['passenger_id' => $driver->id, 'status' => 'requested']),
        ];

        $this->artisan('migrate')->assertExitCode(0);

        $status = fn (string $key) => DB::table('rides')->where('id', $ids[$key])->value('status');
        $this->assertSame('driver_assigned', $status('accepted'));
        $this->assertSame('driver_arriving', $status('en_route'));
        $this->assertSame('driver_arrived', $status('arrived'));
        $this->assertSame('cancelled_by_passenger', $status('by_passenger'));
        $this->assertSame('cancelled_by_operator', $status('by_operator'));
        $this->assertSame('cancelled_by_operator', $status('unknown_canceller'));
        $this->assertSame('completed', $status('completed'));
        $this->assertSame('requested', $status('requested'));
        $this->assertSame(8, DB::table('rides')->count());

        // Each renamed ride says why on its timeline; untouched rides have no event.
        $this->assertSame(6, DB::table('ride_events')->where('type', 'status_migrated')->count());
        $event = DB::table('ride_events')->where('ride_id', $ids['en_route'])->first();
        $this->assertSame('en_route', $event->from_status);
        $this->assertSame('driver_arriving', $event->to_status);
        $this->assertSame('state_machine_v2', $event->reason_code);
        $this->assertSame(0, DB::table('ride_events')->where('ride_id', $ids['completed'])->count());

        // Guard columns filled for live rides.
        $this->assertSame($driver->id, (int) DB::table('rides')->where('id', $ids['accepted'])->value('active_driver_id'));
        $this->assertSame($driver->id, (int) DB::table('rides')->where('id', $ids['requested'])->value('active_passenger_id'));
        $this->assertNull(DB::table('rides')->where('id', $ids['completed'])->value('active_passenger_id'));
    }

    public function test_a_driver_who_held_two_rides_before_v2_keeps_one_guard_and_the_other_is_flagged(): void
    {
        $driver = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->rollBackToBeforeDispatch();

        $older = $this->legacyRide(['passenger_id' => $a->id, 'status' => 'accepted', 'driver_id' => $driver->id]);
        $newer = $this->legacyRide(['passenger_id' => $b->id, 'status' => 'en_route', 'driver_id' => $driver->id]);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame($driver->id, (int) DB::table('rides')->where('id', $newer)->value('active_driver_id'));
        $this->assertNull(DB::table('rides')->where('id', $older)->value('active_driver_id'));
        $this->assertSame('driver_assigned', DB::table('rides')->where('id', $older)->value('status'), 'the status is not touched');
        $this->assertSame(1, DB::table('ride_events')->where('ride_id', $older)->where('reason_code', 'duplicate_active_driver')->count());
    }

    public function test_the_migration_rolls_back_to_the_pre_v2_values(): void
    {
        $passenger = User::factory()->create();
        $id = DB::table('rides')->insertGetId([
            'passenger_id' => $passenger->id, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'vehicle_type' => 'standard',
            'status' => 'driver_arriving', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->rollBackToBeforeDispatch();

        $this->assertSame('en_route', DB::table('rides')->where('id', $id)->value('status'));
    }
}
