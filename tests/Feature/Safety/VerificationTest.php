<?php

namespace Tests\Feature\Safety;

use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\InvalidVerificationAction;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('driver-documents');
    }

    private function driverWithoutDocuments(string $status = 'approved'): array
    {
        $user = $this->makeApprovedDriver('standard', false);
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $user->id)->firstOrFail();
        $profile->forceFill(['status' => $status])->save();

        return [$user, $profile, $profile->vehicles()->firstOrFail()];
    }

    public function test_uploaded_photo_is_reencoded_and_exif_is_stripped(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $source = $this->jpegWithExif('GPS-SECRET-MARKER-123');
        $this->assertStringContainsString('GPS-SECRET-MARKER-123', $source);
        $this->assertStringContainsString('Exif', $source);

        $response = $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'profile_photo',
            'document' => UploadedFile::fake()->createWithContent('me.jpg', $source),
        ])->assertCreated();

        $document = VerificationDocument::findOrFail($response->json('data.id'));
        $stored = Storage::disk('driver-documents')->get($document->file_path);

        $this->assertSame('image/jpeg', $document->mime);
        $this->assertStringNotContainsString('GPS-SECRET-MARKER-123', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame(hash('sha256', $stored), $document->sha256);
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertArrayNotHasKey('file_path', $response->json('data'));
    }

    public function test_type_is_sniffed_from_content_not_the_file_name(): void
    {
        [$driver] = $this->driverWithoutDocuments();

        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'identity_document',
            'document' => UploadedFile::fake()->createWithContent('id.jpg', '<?php echo "hi"; ?>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('document');

        // A profile photo must be an image, a PDF is refused even though PDFs are fine elsewhere.
        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'profile_photo',
            'document' => $this->pdfUpload(),
        ])->assertUnprocessable();

        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'drivers_licence',
            'document' => $this->pdfUpload(),
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.mime', 'application/pdf');
    }

    public function test_expiry_date_is_required_where_the_type_expires_and_must_be_in_the_future(): void
    {
        [$driver] = $this->driverWithoutDocuments();

        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'prdp', 'document' => $this->pdfUpload(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'prdp', 'document' => $this->pdfUpload(), 'expires_on' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_on');
    }

    public function test_vehicle_documents_must_name_one_of_the_drivers_own_vehicles(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        [, , $otherVehicle] = $this->driverWithoutDocuments();

        $this->actingAs($driver)->postJson(route('verification.documents.store'), [
            'type' => 'insurance', 'document' => $this->pdfUpload(), 'expires_on' => now()->addYear()->toDateString(), 'vehicle_id' => $otherVehicle->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_review_state_machine_and_history(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $operator = $this->makeOperator();
        $service = app(VerificationService::class);

        $document = $service->submit($driver, 'drivers_licence', $this->pdfUpload(), null, now()->addYear()->toDateString());
        $rejected = $service->reject($document, $operator, 'illegible', 'Blurry');
        $this->assertSame(DocumentStatus::Rejected, $rejected->status);

        try {
            $service->approve($rejected, $operator);
            $this->fail('A rejected document must not become approved.');
        } catch (InvalidVerificationAction) {
        }

        // Resubmission replaces the rejected upload; approving the new one works.
        $second = $service->submit($driver, 'drivers_licence', $this->pdfUpload(), null, now()->addYear()->toDateString());
        $this->assertSame(DocumentStatus::Superseded, $rejected->fresh()->status);
        $service->approve($second, $operator);

        $actions = $second->fresh()->reviews->pluck('action')->all();
        $this->assertSame(['submitted', 'approved'], $actions);
        $this->assertSame(['submitted', 'rejected', 'superseded'], $rejected->fresh()->reviews->pluck('action')->all());
    }

    public function test_reject_needs_a_listed_reason_and_other_needs_a_note(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $operator = $this->makeOperator();
        $document = app(VerificationService::class)->submit($driver, 'profile_photo', $this->jpegUpload());

        $this->actingAs($operator)->postJson(route('safety.ops.verification.reject', $document), ['reason' => 'made_up'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.ops.verification.reject', $document), ['reason' => 'other'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.ops.verification.resubmission', $document), ['reason' => 'photo_quality'])
            ->assertOk()->assertJsonPath('data.status', 'resubmission_requested');

        $this->assertSame('verification_document_reviewed', $driver->fresh()->notifications->first()->data['type']);
    }

    public function test_reviewers_cannot_review_their_own_documents_and_drivers_cannot_review_at_all(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $document = app(VerificationService::class)->submit($driver, 'profile_photo', $this->jpegUpload());

        $this->actingAs($driver)->postJson(route('safety.ops.verification.approve', $document))->assertForbidden();

        $driver->forceFill(['is_platform_operator' => true])->save();
        $this->actingAs($driver->fresh())->postJson(route('safety.ops.verification.approve', $document))->assertForbidden();
        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);
    }

    public function test_approval_of_an_expiring_type_requires_an_expiry_date(): void
    {
        [$driver, $profile] = $this->driverWithoutDocuments();
        $operator = $this->makeOperator();
        // A carried-over legacy document has no expiry date on record.
        $legacy = VerificationDocument::create([
            'user_id' => $driver->id, 'driver_profile_id' => $profile->id, 'market' => 'ZA', 'subject' => 'driver', 'type' => 'drivers_licence',
            'status' => DocumentStatus::Pending, 'disk' => 'driver-documents', 'file_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(),
        ]);

        $this->actingAs($operator)->postJson(route('safety.ops.verification.approve', $legacy))->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.ops.verification.approve', $legacy), ['expires_on' => now()->addMonths(6)->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_eligibility_lists_structured_reasons_and_allows_a_fully_verified_driver(): void
    {
        [$driver, $profile, $vehicle] = $this->driverWithoutDocuments();
        $eligibility = app(DriverEligibility::class);

        $result = $eligibility->canGoOnline($driver, $vehicle);
        $this->assertFalse($result->allowed());
        $this->assertContains('document_missing', $result->codes());
        $this->assertContains('prdp', array_column($result->reasons, 'type'));
        $this->assertContains('insurance', array_column($result->reasons, 'type'));

        $this->approveAllDocuments($profile, $vehicle);
        $this->assertTrue($eligibility->canGoOnline($driver, $vehicle)->allowed());
    }

    public function test_unapproved_or_suspended_driver_and_inactive_or_foreign_vehicle_are_refused(): void
    {
        [$driver, $profile, $vehicle] = $this->driverWithoutDocuments('pending');
        $this->approveAllDocuments($profile, $vehicle);
        $eligibility = app(DriverEligibility::class);

        $this->assertSame(['driver_not_approved'], $eligibility->canGoOnline($driver, $vehicle)->codes());

        $profile->forceFill(['status' => 'suspended'])->save();
        $this->assertSame(['driver_suspended'], $eligibility->canGoOnline($driver, $vehicle)->codes());

        $profile->forceFill(['status' => 'approved'])->save();
        $vehicle->forceFill(['is_active' => false])->save();
        $this->assertSame(['vehicle_inactive'], $eligibility->canGoOnline($driver, $vehicle)->codes());

        [, , $foreign] = $this->makeVerifiedDriver();
        $this->assertContains('vehicle_not_owned', $eligibility->canGoOnline($driver, $foreign)->codes());
    }

    public function test_a_pending_renewal_does_not_take_a_verified_driver_offline(): void
    {
        [$driver, $profile, $vehicle] = $this->makeVerifiedDriver();
        app(VerificationService::class)->submit($driver, 'drivers_licence', $this->pdfUpload(), null, now()->addYears(2)->toDateString());

        $this->assertTrue(app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->allowed());
        $item = collect(app(VerificationService::class)->driverStatus($profile)['items'])->firstWhere('type', 'drivers_licence');
        $this->assertTrue($item['renewal_pending']);
    }

    public function test_premium_vehicle_photos_older_than_the_category_allows_block_going_online(): void
    {
        [$driver, $profile, $vehicle] = $this->makeVerifiedDriver('premium');
        $this->assertTrue(app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->allowed());

        $this->travel(181)->days();

        $result = app(DriverEligibility::class)->canGoOnline($driver, $vehicle);
        $this->assertFalse($result->allowed());
        $this->assertSame(['document_too_old'], $result->codes());
    }

    public function test_expired_mandatory_document_blocks_going_online_and_takes_an_online_driver_offline(): void
    {
        [$driver, $profile, $vehicle] = $this->makeVerifiedDriver('standard', true);
        $this->assertTrue($profile->fresh()->is_online);

        $insurance = VerificationDocument::query()->where('vehicle_id', $vehicle->id)->where('type', 'insurance')->firstOrFail();
        $insurance->forceFill(['expires_on' => now()->addDays(2)->toDateString()])->save();

        $this->travel(3)->days();

        // The gate reads the date itself, before any sweep has run.
        $this->assertSame(['document_expired'], app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->codes());

        $this->artisan('ehail:verification-expiry')->assertSuccessful();

        $this->assertSame(DocumentStatus::Expired, $insurance->fresh()->status);
        $this->assertFalse($profile->fresh()->is_online);
        $this->assertTrue(AuditLog::where('action', 'driver.forced_offline')->exists());

        // Going online again through the existing toggle is refused with the reason.
        $this->actingAs($driver)->patch(route('drivers.toggle-online', $profile))->assertSessionHasErrors('online');
        $this->assertFalse($profile->fresh()->is_online);
    }

    public function test_expiry_warnings_are_sent_once_at_30_and_7_days(): void
    {
        [$driver, , $vehicle] = $this->makeVerifiedDriver();
        VerificationDocument::query()->where('vehicle_id', $vehicle->id)->where('type', 'insurance')->update(['expires_on' => now()->addDays(20)->toDateString()]);

        $service = app(VerificationService::class);
        $this->assertSame(1, $service->warnExpiring());
        $this->assertSame(0, $service->warnExpiring());

        $this->travel(14)->days();
        $this->assertSame(1, $service->warnExpiring());
        $this->assertSame(0, $service->warnExpiring());

        $this->assertCount(2, $driver->fresh()->notifications->where('data.type', 'verification_document_expiring'));
    }

    public function test_legacy_toggle_refuses_unapproved_and_unverified_drivers(): void
    {
        [$pending, $pendingProfile, $vehicle] = $this->driverWithoutDocuments('pending');
        $this->approveAllDocuments($pendingProfile, $vehicle);
        $this->actingAs($pending)->patch(route('drivers.toggle-online', $pendingProfile))->assertSessionHasErrors('online');
        $this->assertFalse($pendingProfile->fresh()->is_online);

        [$unverified, $unverifiedProfile] = $this->driverWithoutDocuments();
        $this->actingAs($unverified)
            ->patchJson(route('drivers.toggle-online', $unverifiedProfile))
            ->assertUnprocessable()
            ->assertJsonPath('allowed', false);
    }

    public function test_document_file_access_is_restricted_and_every_non_owner_view_is_logged(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $document = app(VerificationService::class)->submit($driver, 'profile_photo', $this->jpegUpload());
        $stranger = User::factory()->withPersonalTeam()->create();
        $operator = $this->makeOperator();

        $this->actingAs($stranger)->get(route('verification.documents.file', $document))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('verification.documents.show', $document))->assertNotFound();

        $this->actingAs($driver)->get(route('verification.documents.file', $document))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(0, AuditLog::where('action', 'verification_document.viewed')->count());

        $this->actingAs($operator)->get(route('verification.documents.file', $document))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'verification_document.viewed')->where('actor_user_id', $operator->id)->count());

        // A fleet owner is deliberately not allowed to open identity documents.
        $fleetOwner = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first()->fleet->owner;
        $this->actingAs($fleetOwner)->get(route('verification.documents.file', $document))->assertNotFound();
    }

    public function test_assigned_reviewer_can_view_and_queue_is_operator_only(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $document = app(VerificationService::class)->submit($driver, 'profile_photo', $this->jpegUpload());
        $operator = $this->makeOperator();

        $this->actingAs($driver)->getJson(route('safety.ops.verification.queue'))->assertForbidden();
        $this->actingAs($operator)->getJson(route('safety.ops.verification.queue'))->assertOk()->assertJsonPath('data.0.id', $document->id);

        $this->actingAs($operator)->postJson(route('safety.ops.verification.assign', $document))->assertOk();
        $this->assertSame($operator->id, $document->fresh()->assigned_reviewer_id);
        $this->actingAs($operator)->getJson(route('safety.ops.verification.queue', ['assigned' => 'me']))->assertJsonCount(1, 'data');
    }

    public function test_driver_status_endpoint_reports_eligibility_and_the_owner_history_hides_reviewer_identity(): void
    {
        [$driver] = $this->driverWithoutDocuments();
        $operator = $this->makeOperator();
        $document = app(VerificationService::class)->submit($driver, 'profile_photo', $this->jpegUpload());
        app(VerificationService::class)->reject($document, $operator, 'photo_quality');

        $this->actingAs($driver)->getJson(route('verification.status'))
            ->assertOk()
            ->assertJsonPath('eligibility.allowed', false)
            ->assertJsonPath('driver.status', 'action_required');

        $history = $this->actingAs($driver)->getJson(route('verification.documents.show', $document))->json('history');
        $this->assertNull($history[1]['actor_id']);
        $this->assertSame('rejected', $history[1]['action']);
    }
}
