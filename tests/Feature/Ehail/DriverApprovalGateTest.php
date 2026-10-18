<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The approval gate: a driver profile may only be approved by a fleet owner
 * who is NOT the applicant, or by a platform operator who is NOT the
 * applicant. Previously an applicant could create a brand-new fleet in the
 * same form, become its owner, and approve their own application.
 */
class DriverApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'license_number' => 'LIC-ATTACK',
            'id_number' => 'ID-ATTACK',
            'new_fleet_name' => 'Self Approving Fleet',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year' => 2022,
            'vehicle_color' => 'White',
            'vehicle_plate_number' => 'CA 424242',
            'vehicle_type' => 'standard',
        ], $overrides);
    }

    private function pendingApplication(Fleet $fleet, ?User $applicant = null): DriverProfile
    {
        $applicant ??= User::factory()->create();
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

    private function statusOf(DriverProfile $profile): string
    {
        return DriverProfile::withoutGlobalScope('user')->find($profile->id)->status;
    }

    public function test_applicant_cannot_approve_their_own_application_through_a_fleet_they_just_created(): void
    {
        Notification::fake();
        $attacker = User::factory()->create();

        $this->actingAs($attacker)
            ->post(route('drive.apply.store'), $this->applicationPayload())
            ->assertRedirect();

        $fleet = Fleet::firstOrFail();
        $profile = DriverProfile::withoutGlobalScope('user')->firstOrFail();
        $this->assertSame($attacker->id, $fleet->owner_user_id);

        $this->actingAs($attacker)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $this->assertSame('pending', $this->statusOf($profile));
        $this->assertNull(DriverProfile::withoutGlobalScope('user')->find($profile->id)->reviewed_by);
    }

    public function test_a_fleet_created_by_an_application_starts_pending_operator_review(): void
    {
        Notification::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('drive.apply.store'), $this->applicationPayload())
            ->assertRedirect();

        $this->assertSame('pending', Fleet::firstOrFail()->status);
    }

    public function test_the_owner_of_an_approved_fleet_cannot_approve_their_own_driver_application(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Vetted Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet, $owner);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $this->assertSame('pending', $this->statusOf($profile));
    }

    public function test_a_platform_operator_cannot_approve_their_own_application_either(): void
    {
        $operator = $this->operator();
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Vetted Fleet', 'owner_user_id' => User::factory()->create()->id]);
        $profile = $this->pendingApplication($fleet, $operator);

        $this->actingAs($operator)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $this->assertSame('pending', $this->statusOf($profile));
    }

    public function test_the_owner_of_a_still_pending_fleet_cannot_approve_other_applicants(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $this->assertSame('pending', $this->statusOf($profile));
    }

    public function test_a_non_applicant_owner_of_an_approved_fleet_can_approve(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Vetted Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertRedirect();

        $this->assertSame('approved', $this->statusOf($profile));
    }

    public function test_a_platform_operator_can_approve_an_application_in_a_pending_fleet(): void
    {
        $operator = $this->operator();
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => User::factory()->create()->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($operator)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertRedirect();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
    }

    public function test_the_self_owned_pending_fleet_applicant_cannot_reject_either_but_operator_can(): void
    {
        $attacker = User::factory()->create();
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => $attacker->id]);
        $profile = $this->pendingApplication($fleet, $attacker);

        $this->actingAs($attacker)
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), ['reason' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->operator())
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), ['reason' => 'Unverified fleet.'])
            ->assertRedirect();

        $this->assertSame('rejected', $this->statusOf($profile));
    }

    public function test_the_platform_operator_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Mallory', 'email' => 'mallory@example.test', 'password' => 'password',
            'is_platform_operator' => true,
        ]);

        $this->assertFalse((bool) $user->fresh()->is_platform_operator);
    }

    public function test_an_operator_can_view_any_fleet_but_a_stranger_cannot(): void
    {
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($this->operator())->get(route('fleets.show', $fleet))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('fleets.show', $fleet))->assertForbidden();
    }

    public function test_a_platform_operator_can_approve_a_pending_fleet_and_others_cannot(): void
    {
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($fleet->owner)
            ->post(route('operator.fleets.approve', $fleet))
            ->assertForbidden();
        $this->assertSame('pending', $fleet->fresh()->status);

        $this->actingAs($this->operator())
            ->post(route('operator.fleets.approve', $fleet))
            ->assertRedirect();
        $this->assertSame('approved', $fleet->fresh()->status);
    }

    public function test_the_operator_fleet_queue_is_operator_only_and_lists_pending_fleets(): void
    {
        Fleet::create(['status' => 'pending', 'name' => 'Queued Fleet Name', 'owner_user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create())->get(route('operator.fleets.index'))->assertForbidden();
        $this->actingAs($this->operator())->get(route('operator.fleets.index'))->assertOk()->assertSee('Queued Fleet Name');
    }

    public function test_an_application_cannot_target_a_fleet_that_is_still_pending(): void
    {
        Notification::fake();
        $fleet = Fleet::create(['status' => 'pending', 'name' => 'Unvetted Fleet', 'owner_user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('drive.apply.store'), $this->applicationPayload([
                'existing_fleet_id' => $fleet->id,
                'new_fleet_name' => null,
            ]))
            ->assertSessionHasErrors('existing_fleet_id');

        $this->assertSame(0, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_an_application_to_a_new_fleet_notifies_operators_not_the_applicant(): void
    {
        Notification::fake();
        $operator = $this->operator();
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->applicationPayload())
            ->assertRedirect();

        Notification::assertSentTo($operator, DriverApplicationSubmittedNotification::class);
        Notification::assertNotSentTo($applicant, DriverApplicationSubmittedNotification::class);
    }
}
