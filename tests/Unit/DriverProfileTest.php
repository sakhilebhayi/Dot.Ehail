<?php

namespace Tests\Unit;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_profile_belongs_to_a_fleet(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-300',
            'id_number' => 'ID-300',
            'status' => 'pending',
        ]);

        $this->assertTrue($profile->fleet->is($fleet));
    }

    public function test_status_can_be_rejected_with_a_reason_and_reviewer(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-301',
            'id_number' => 'ID-301',
            'status' => 'pending',
        ]);

        $profile->update([
            'status' => 'rejected',
            'rejected_reason' => 'Expired license.',
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $profile->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Expired license.', $fresh->rejected_reason);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }
}
