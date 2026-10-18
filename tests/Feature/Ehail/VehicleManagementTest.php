<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class VehicleManagementTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'make' => 'Hyundai', 'model' => 'i20', 'year' => 2021, 'color' => 'Silver',
            'plate_number' => 'CA 771 204', 'type' => 'economy', 'seats' => 4,
        ], $overrides);
    }

    private function profileOf(User $driver): DriverProfile
    {
        return DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
    }

    // ─── Listing ─────────────────────────────────────────────────────────

    public function test_a_driver_sees_their_own_vehicles_only(): void
    {
        $driver = $this->makeApprovedDriver();
        $other = $this->makeApprovedDriver();
        Vehicle::factory()->for($this->profileOf($driver), 'driverProfile')->create(['plate_number' => 'MINE 111 ZZ', 'is_active' => false]);
        Vehicle::factory()->for($this->profileOf($other), 'driverProfile')->create(['plate_number' => 'THEIRS 222 ZZ', 'is_active' => false]);

        $this->actingAs($driver)->get(route('drivers.vehicles.index'))
            ->assertOk()
            ->assertSee('MINE 111 ZZ')
            ->assertDontSee('THEIRS 222 ZZ');
    }

    public function test_someone_who_is_not_a_driver_is_sent_to_apply(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('drivers.vehicles.index'))->assertRedirect(route('drive.apply'));
        $this->actingAs($user)->post(route('drivers.vehicles.store'), $this->payload())->assertRedirect(route('drive.apply'));
        $this->assertSame(0, Vehicle::count());
    }

    // ─── Add ─────────────────────────────────────────────────────────────

    public function test_an_added_vehicle_is_inactive_while_another_is_active(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload())
            ->assertRedirect(route('drivers.vehicles.index'));

        $added = Vehicle::where('plate_number', 'CA 771 204')->firstOrFail();
        $this->assertFalse($added->is_active);
        $this->assertSame(1, $this->profileOf($driver)->vehicles()->where('is_active', true)->count());
    }

    public function test_the_first_vehicle_becomes_active_automatically(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->for($user)->create(['status' => 'approved']);

        $this->actingAs($user)->post(route('drivers.vehicles.store'), $this->payload());

        $this->assertTrue(Vehicle::firstOrFail()->is_active);
    }

    public function test_adding_with_activate_switches_the_active_vehicle(): void
    {
        $driver = $this->makeApprovedDriver();
        $previous = $this->profileOf($driver)->activeVehicle();

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload(['activate' => '1']));

        $this->assertFalse($previous->fresh()->is_active);
        $this->assertSame('CA 771 204', $this->profileOf($driver)->activeVehicle()->plate_number);
        $this->assertSame(1, $this->profileOf($driver)->vehicles()->where('is_active', true)->count());
    }

    public function test_the_plate_is_stored_trimmed_and_upper_case(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload(['plate_number' => '  ca 771 204 ']));

        $this->assertNotNull(Vehicle::where('plate_number', 'CA 771 204')->first());
    }

    public function test_vehicle_fields_are_validated(): void
    {
        $driver = $this->makeApprovedDriver();
        $bad = [
            ['make' => ''], ['model' => ''], ['color' => ''], ['plate_number' => ''],
            ['year' => 1950], ['year' => 'abc'], ['year' => (int) date('Y') + 5],
            ['type' => 'hovercraft'], ['seats' => 0], ['seats' => 9], ['seats' => 'x'],
            ['make' => str_repeat('a', 101)],
        ];

        foreach ($bad as $override) {
            $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload($override))
                ->assertSessionHasErrors(array_key_first($override));
        }
        $this->assertSame(1, Vehicle::count(), 'only the fixture vehicle exists');
    }

    public function test_a_suspended_or_rejected_driver_cannot_add_vehicles(): void
    {
        foreach (['suspended', 'rejected'] as $status) {
            $user = User::factory()->withPersonalTeam()->create();
            DriverProfile::factory()->for($user)->create(['status' => $status]);

            $this->actingAs($user)->post(route('drivers.vehicles.store'), $this->payload(['plate_number' => "X {$status}"]))->assertForbidden();
        }
        $this->assertSame(0, Vehicle::count());
    }

    // ─── Plates ──────────────────────────────────────────────────────────

    public function test_a_plate_already_in_the_same_fleet_is_refused_by_name(): void
    {
        $fleet = Fleet::factory()->create();
        $driver = $this->makeApprovedDriver();
        $this->profileOf($driver)->update(['fleet_id' => $fleet->id]);
        $colleague = User::factory()->withPersonalTeam()->create();
        $colleagueProfile = DriverProfile::factory()->for($colleague)->create(['fleet_id' => $fleet->id, 'status' => 'approved']);
        Vehicle::factory()->for($colleagueProfile, 'driverProfile')->create(['plate_number' => 'CA 771 204', 'is_active' => true]);

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload())
            ->assertSessionHasErrors(['plate_number' => 'That plate is already registered in your fleet.']);
    }

    public function test_a_plate_registered_in_another_fleet_is_refused_too(): void
    {
        $driver = $this->makeApprovedDriver();
        $stranger = DriverProfile::factory()->create(['status' => 'approved']);
        Vehicle::factory()->for($stranger, 'driverProfile')->create(['plate_number' => 'CA 771 204']);

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload())
            ->assertSessionHasErrors(['plate_number' => 'That plate is already registered on Dot.Ehail.']);
    }

    public function test_plate_uniqueness_ignores_case_and_surrounding_spaces(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload(['plate_number' => 'CA 771 204']))->assertSessionDoesntHaveErrors();

        $this->actingAs($driver)->post(route('drivers.vehicles.store'), $this->payload(['plate_number' => ' ca 771 204 ']))
            ->assertSessionHasErrors('plate_number');
        $this->assertSame(1, Vehicle::where('plate_number', 'CA 771 204')->count());
    }

    // ─── Edit ────────────────────────────────────────────────────────────

    public function test_a_driver_edits_their_own_vehicle_and_keeping_the_plate_is_not_a_clash(): void
    {
        $driver = $this->makeApprovedDriver();
        $vehicle = $this->profileOf($driver)->activeVehicle();

        $this->actingAs($driver)->get(route('drivers.vehicles.edit', $vehicle))->assertOk()->assertSee($vehicle->plate_number);

        $this->actingAs($driver)->patch(route('drivers.vehicles.update', $vehicle), $this->payload([
            'plate_number' => $vehicle->plate_number, 'color' => 'Midnight blue', 'seats' => 6, 'type' => 'suv',
        ]))->assertRedirect(route('drivers.vehicles.index'))->assertSessionDoesntHaveErrors();

        $vehicle->refresh();
        $this->assertSame('Midnight blue', $vehicle->color);
        $this->assertSame(6, $vehicle->seats);
        $this->assertSame('suv', $vehicle->type);
    }

    public function test_a_driver_cannot_edit_or_switch_someone_elses_vehicle(): void
    {
        $driver = $this->makeApprovedDriver();
        $other = $this->makeApprovedDriver();
        $theirs = $this->profileOf($other)->activeVehicle();

        $this->actingAs($driver)->get(route('drivers.vehicles.edit', $theirs))->assertForbidden();
        $this->actingAs($driver)->patch(route('drivers.vehicles.update', $theirs), $this->payload())->assertForbidden();
        $this->actingAs($driver)->post(route('drivers.vehicles.activate', $theirs))->assertForbidden();
        $this->actingAs($driver)->post(route('drivers.vehicles.deactivate', $theirs))->assertForbidden();

        $this->assertSame('Toyota', $theirs->fresh()->make);
    }

    public function test_a_vehicle_on_a_live_ride_cannot_be_edited(): void
    {
        $driver = $this->makeApprovedDriver();
        $vehicle = $this->profileOf($driver)->activeVehicle();
        $this->makeRide(['status' => 'en_route', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

        $this->actingAs($driver)->patch(route('drivers.vehicles.update', $vehicle), $this->payload(['plate_number' => $vehicle->plate_number]))
            ->assertSessionHasErrors('vehicle');

        $this->assertSame('Toyota', $vehicle->fresh()->make);
    }

    // ─── Exactly one active ──────────────────────────────────────────────

    public function test_activating_a_vehicle_deactivates_the_others(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = $this->profileOf($driver);
        $first = $profile->activeVehicle();
        $second = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => false]);

        $this->actingAs($driver)->post(route('drivers.vehicles.activate', $second))->assertRedirect(route('drivers.vehicles.index'));

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_the_database_refuses_a_second_active_vehicle_for_one_driver(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL has no partial unique index; the service enforces the rule there.');
        }

        $driver = $this->makeApprovedDriver();
        $profile = $this->profileOf($driver);

        $this->expectException(QueryException::class);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
    }

    public function test_deactivating_the_active_vehicle_takes_the_driver_offline(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = $this->profileOf($driver);
        $this->assertTrue($profile->is_online);

        $this->actingAs($driver)->post(route('drivers.vehicles.deactivate', $profile->activeVehicle()))
            ->assertRedirect(route('drivers.vehicles.index'));

        $this->assertNull($profile->fresh()->activeVehicle());
        $this->assertFalse($profile->fresh()->is_online, 'a driver with no active vehicle cannot be online');
    }

    public function test_the_active_vehicle_on_a_live_ride_cannot_be_deactivated(): void
    {
        $driver = $this->makeApprovedDriver();
        $vehicle = $this->profileOf($driver)->activeVehicle();
        $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

        $this->actingAs($driver)->post(route('drivers.vehicles.deactivate', $vehicle))->assertSessionHasErrors('vehicle');

        $this->assertTrue($vehicle->fresh()->is_active);
    }

    // ─── Fleet owners ────────────────────────────────────────────────────

    public function test_the_owner_of_an_approved_fleet_sees_their_drivers_vehicles(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = $this->profileOf($driver)->load('fleet');
        $profile->activeVehicle()->update(['plate_number' => 'FLEETVEH 901 GP']);

        $this->actingAs($profile->fleet->owner)->get(route('fleets.show', $profile->fleet))
            ->assertOk()
            ->assertSee('FLEETVEH 901 GP');
    }

    public function test_the_owner_of_a_pending_fleet_does_not_get_the_vehicle_register_yet(): void
    {
        $fleet = Fleet::factory()->create(['status' => 'pending']);
        $profile = DriverProfile::factory()->create(['fleet_id' => $fleet->id, 'status' => 'approved']);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['plate_number' => 'PENDINGVEH 902 GP']);

        $this->actingAs($fleet->owner)->get(route('fleets.show', $fleet))
            ->assertOk()
            ->assertDontSee('Vehicle register');
    }

    public function test_an_operator_sees_vehicles_of_any_fleet_and_other_owners_cannot_open_it(): void
    {
        $driver = $this->makeApprovedDriver();
        $profile = $this->profileOf($driver)->load('fleet');
        $profile->activeVehicle()->update(['plate_number' => 'FLEETVEH 903 GP']);

        $this->actingAs($this->makeOperator())->get(route('fleets.show', $profile->fleet))->assertOk()->assertSee('FLEETVEH 903 GP');

        $otherOwner = Fleet::factory()->create()->owner;
        $this->actingAs($otherOwner)->get(route('fleets.show', $profile->fleet))->assertForbidden();
    }

    // ─── Vehicle documents (existing upload pattern) ─────────────────────

    public function test_a_driver_attaches_an_inspection_document_to_a_vehicle_through_the_existing_upload(): void
    {
        Storage::fake('driver-documents');
        $driver = $this->makeApprovedDriver();
        $vehicle = $this->profileOf($driver)->activeVehicle();

        $this->actingAs($driver)->post(route('drivers.documents.store'), [
            'type' => 'vehicle_inspection',
            'vehicle_id' => $vehicle->id,
            'document' => UploadedFile::fake()->create('roadworthy-certificate.pdf', 50, 'application/pdf'),
        ])->assertSessionDoesntHaveErrors();

        $document = DriverDocument::firstOrFail();
        $this->assertSame($vehicle->id, $document->vehicle_id);
        Storage::disk('driver-documents')->assertExists($document->file_path);

        $this->actingAs($driver)->get(route('drivers.vehicles.index'))->assertOk()->assertSee('roadworthy-certificate.pdf');
    }

    public function test_a_document_cannot_be_attached_to_someone_elses_vehicle_or_a_non_vehicle_type(): void
    {
        Storage::fake('driver-documents');
        $driver = $this->makeApprovedDriver();
        $other = $this->makeApprovedDriver();
        $theirs = $this->profileOf($other)->activeVehicle();
        $file = fn () => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf');

        $this->actingAs($driver)->post(route('drivers.documents.store'), ['type' => 'vehicle_inspection', 'vehicle_id' => $theirs->id, 'document' => $file()])
            ->assertSessionHasErrors('vehicle_id');
        $this->actingAs($driver)->post(route('drivers.documents.store'), ['type' => 'license', 'vehicle_id' => $this->profileOf($driver)->activeVehicle()->id, 'document' => $file()])
            ->assertSessionHasErrors('vehicle_id');

        $this->assertSame(0, DriverDocument::count());
    }

    public function test_the_existing_profile_level_upload_still_works_without_a_vehicle(): void
    {
        Storage::fake('driver-documents');
        $driver = $this->makeApprovedDriver();

        $this->actingAs($driver)->post(route('drivers.documents.store'), [
            'type' => 'license', 'document' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'),
        ])->assertSessionDoesntHaveErrors();

        $this->assertNull(DriverDocument::firstOrFail()->vehicle_id);
    }
}
