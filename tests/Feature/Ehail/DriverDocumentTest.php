<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Roadmap item (wiki.md §8): driver document/inspection workflow, beyond
 * the bare license_number/id_number strings previously modeled.
 */
class DriverDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('driver-documents');
    }

    private function makeProfile(?User $owner = null): DriverProfile
    {
        $owner ??= User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => User::factory()->create()->id]);

        return DriverProfile::create([
            'user_id' => $owner->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-'.uniqid(),
            'id_number' => 'ID-'.uniqid(),
            'status' => 'pending',
        ]);
    }

    public function test_applying_with_documents_attaches_them_to_the_new_profile(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post(route('drive.apply.store'), [
            'license_number' => 'LIC-900',
            'id_number' => 'ID-900',
            'new_fleet_name' => 'My Fleet',
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla', 'vehicle_year' => 2022,
            'vehicle_color' => 'White', 'vehicle_plate_number' => 'CA 900900', 'vehicle_type' => 'standard',
            'license_document' => UploadedFile::fake()->create('license.pdf', 100),
        ]);

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame(1, $profile->documents()->count());
        $this->assertSame('license', $profile->documents()->first()->type);
    }

    public function test_applying_without_documents_still_succeeds(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post(route('drive.apply.store'), [
            'license_number' => 'LIC-901',
            'id_number' => 'ID-901',
            'new_fleet_name' => 'My Fleet',
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla', 'vehicle_year' => 2022,
            'vehicle_color' => 'White', 'vehicle_plate_number' => 'CA 901901', 'vehicle_type' => 'standard',
        ])->assertRedirect();

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame(0, $profile->documents()->count());
    }

    public function test_a_driver_can_upload_an_additional_document_later(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = $this->makeProfile($user);

        $this->actingAs($user)->post(route('drivers.documents.store'), [
            'type' => 'vehicle_inspection',
            'document' => UploadedFile::fake()->create('inspection.pdf', 100),
        ])->assertRedirect(route('drivers.show', $profile));

        $this->assertSame(1, $profile->documents()->count());
    }

    public function test_the_driver_can_view_their_own_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = $this->makeProfile($user);
        $document = $profile->documents()->create([
            'type' => 'license', 'file_path' => 'somefile.pdf', 'original_filename' => 'license.pdf',
        ]);
        Storage::disk('driver-documents')->put('somefile.pdf', 'fake contents');

        $this->actingAs($user)
            ->get(route('drivers.documents.show', $document))
            ->assertOk();
    }

    public function test_the_fleet_owner_can_view_an_applicants_document(): void
    {
        $fleetOwner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $fleetOwner->id]);
        $applicant = User::factory()->create();
        $profile = DriverProfile::create([
            'user_id' => $applicant->id, 'fleet_id' => $fleet->id,
            'license_number' => 'LIC-902', 'id_number' => 'ID-902', 'status' => 'pending',
        ]);
        $document = $profile->documents()->create([
            'type' => 'license', 'file_path' => 'somefile.pdf', 'original_filename' => 'license.pdf',
        ]);
        Storage::disk('driver-documents')->put('somefile.pdf', 'fake contents');

        $this->actingAs($fleetOwner)
            ->get(route('drivers.documents.show', $document))
            ->assertOk();
    }

    public function test_an_unrelated_user_cannot_view_a_drivers_document(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $profile = $this->makeProfile();
        $document = $profile->documents()->create([
            'type' => 'license', 'file_path' => 'somefile.pdf', 'original_filename' => 'license.pdf',
        ]);

        $this->actingAs($stranger)
            ->get(route('drivers.documents.show', $document))
            ->assertForbidden();
    }

    public function test_a_driver_can_delete_their_own_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = $this->makeProfile($user);
        $document = $profile->documents()->create([
            'type' => 'license', 'file_path' => 'somefile.pdf', 'original_filename' => 'license.pdf',
        ]);
        Storage::disk('driver-documents')->put('somefile.pdf', 'fake contents');

        $this->actingAs($user)
            ->delete(route('drivers.documents.destroy', $document))
            ->assertRedirect(route('drivers.show', $profile));

        $this->assertDatabaseMissing('driver_documents', ['id' => $document->id]);
    }

    public function test_a_fleet_owner_cannot_delete_an_applicants_document(): void
    {
        $fleetOwner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $fleetOwner->id]);
        $applicant = User::factory()->create();
        $profile = DriverProfile::create([
            'user_id' => $applicant->id, 'fleet_id' => $fleet->id,
            'license_number' => 'LIC-903', 'id_number' => 'ID-903', 'status' => 'pending',
        ]);
        $document = $profile->documents()->create([
            'type' => 'license', 'file_path' => 'somefile.pdf', 'original_filename' => 'license.pdf',
        ]);

        $this->actingAs($fleetOwner)
            ->delete(route('drivers.documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('driver_documents', ['id' => $document->id]);
    }
}
