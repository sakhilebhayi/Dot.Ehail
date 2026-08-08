<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDriverReviewTest extends TestCase
{
    use RefreshDatabase;

    private function pendingApplication(Fleet $fleet): DriverProfile
    {
        $applicant = User::factory()->create();
        $profile = DriverProfile::create([
            'user_id' => $applicant->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-'.$applicant->id,
            'id_number' => 'ID-'.$applicant->id,
            'status' => 'pending',
        ]);
        Vehicle::create([
            'driver_profile_id' => $profile->id,
            'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2022, 'color' => 'White',
            'plate_number' => 'CA '.$applicant->id, 'type' => 'standard',
        ]);

        return $profile;
    }

    public function test_fleet_owner_can_view_pending_applications(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->get(route('fleets.show', $fleet))
            ->assertOk()
            ->assertSee($profile->license_number);
    }

    public function test_fleet_owner_can_approve_a_pending_driver(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertRedirect();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_reject_without_a_reason_is_blocked(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), [])
            ->assertSessionHasErrors('reason');

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_reject_with_a_reason_marks_the_driver_rejected(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), ['reason' => 'Expired license.'])
            ->assertRedirect();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Expired license.', $fresh->rejected_reason);
    }

    public function test_a_different_fleets_owner_cannot_view_or_approve(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $stranger = User::factory()->create();
        Fleet::create(['name' => 'Other Fleet', 'owner_user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->get(route('fleets.show', $fleet))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_approving_an_already_decided_driver_is_refused(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);
        $profile->update(['status' => 'approved']);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertSessionHasErrors();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('approved', $fresh->status);
    }
}
