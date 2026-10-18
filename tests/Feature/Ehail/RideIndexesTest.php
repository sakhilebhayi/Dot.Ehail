<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RideIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hot_query_paths_are_indexed(): void
    {
        $this->assertTrue(Schema::hasIndex('rides', ['status', 'requested_at']));
        $this->assertTrue(Schema::hasIndex('rides', ['driver_id', 'status']));
        $this->assertTrue(Schema::hasIndex('rides', ['passenger_id', 'status']));
        $this->assertTrue(Schema::hasIndex('driver_profiles', ['fleet_id', 'status']));
    }

    public function test_the_status_column_accepts_the_new_states(): void
    {
        foreach (['expired', 'needs_review'] as $status) {
            Ride::create([
                'passenger_id' => User::factory()->create()->id,
                'pickup_address' => 'a', 'dropoff_address' => 'b', 'status' => $status,
            ]);
        }

        $this->assertSame(2, Ride::whereIn('status', ['expired', 'needs_review'])->count());
    }
}
