<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DriverApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'license_number' => 'LIC-500',
            'id_number' => 'ID-500',
            'new_fleet_name' => 'My Own Fleet',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year' => 2022,
            'vehicle_color' => 'White',
            'vehicle_plate_number' => 'CA 123456',
            'vehicle_type' => 'standard',
        ], $overrides);
    }

    public function test_applying_with_a_new_fleet_name_creates_the_fleet_and_a_pending_profile(): void
    {
        Notification::fake();
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, Fleet::count());
        $fleet = Fleet::first();
        $this->assertSame($applicant->id, $fleet->owner_user_id);
        $this->assertSame('My Own Fleet', $fleet->name);

        $profile = DriverProfile::withoutGlobalScope('user')->first();
        $this->assertSame('pending', $profile->status);
        $this->assertSame($fleet->id, $profile->fleet_id);
        $this->assertSame($applicant->id, $profile->user_id);
        $this->assertSame(1, $profile->vehicles()->count());
    }

    public function test_applying_to_an_existing_fleet_does_not_create_a_second_fleet(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                'new_fleet_name' => null,
            ]))
            ->assertRedirect();

        $this->assertSame(1, Fleet::count());
        $profile = DriverProfile::withoutGlobalScope('user')->first();
        $this->assertSame($fleet->id, $profile->fleet_id);
    }

    public function test_submitting_with_both_fleet_options_fails_validation(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                // new_fleet_name also present from validPayload()
            ]))
            ->assertSessionHasErrors();

        $this->assertSame(0, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_submitting_with_neither_fleet_option_fails_validation(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload(['new_fleet_name' => null]))
            ->assertSessionHasErrors();

        $this->assertSame(0, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_the_fleet_owner_receives_a_real_notification(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post(route('drive.apply.store'), $this->validPayload([
            'existing_fleet_id' => $fleet->id,
            'new_fleet_name' => null,
        ]));

        Notification::assertSentTo($owner, DriverApplicationSubmittedNotification::class);
    }

    public function test_a_user_who_already_has_a_driver_profile_cannot_submit_a_second_one(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();
        DriverProfile::create([
            'user_id' => $applicant->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-EXISTING',
            'id_number' => 'ID-EXISTING',
            'status' => 'pending',
        ]);

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                'new_fleet_name' => null,
                'license_number' => 'LIC-SECOND',
                'id_number' => 'ID-SECOND',
                'vehicle_plate_number' => 'CA 999999',
            ]))
            ->assertSessionHasErrors();

        $this->assertSame(1, DriverProfile::withoutGlobalScope('user')->count());
    }
}
