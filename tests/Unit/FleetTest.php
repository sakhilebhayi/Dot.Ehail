<?php

namespace Tests\Unit;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fleet_belongs_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);

        $this->assertTrue($fleet->owner->is($owner));
    }
}
