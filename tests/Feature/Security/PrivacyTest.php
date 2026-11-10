<?php

namespace Tests\Feature\Security;

use App\Actions\Jetstream\DeleteUser;
use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Security\KnownDevices;
use App\Domain\Verification\Models\VerificationDocument;
use App\Http\Middleware\RequireRecentSignIn;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use App\Notifications\SecurityNoticeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MoneyFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-46 security S-20..S-25, S-27, S-28 (docs/R46_PRIVACY.md): erasure keeps the records the law needs, access
 * requests return only the person's own data, the retention schedule runs, positions and notices carry no more
 * than needed.
 */
class PrivacyTest extends TestCase
{
    use MoneyFixtures, OpsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
    }

    public function test_closing_an_account_with_history_anonymises_and_keeps_the_records(): void
    {
        Storage::fake('driver-documents');
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena', 'email' => 'thandi@example.test']);
        $this->savedCard($passenger);
        $ride = $this->completeTrip($passenger, null, '120.00');
        TrustedContact::create(['user_id' => $passenger->id, 'name' => 'Sipho', 'email' => 'sipho@example.com', 'email_hash' => hash('sha256', 'sipho@example.com')]);
        $journals = DB::table('ledger_journals')->count();
        $receipts = DB::table('receipts')->count();

        app(DeleteUser::class)->delete($passenger);

        $closed = User::find($passenger->id);
        $this->assertNotNull($closed, 'the account row stays as a tombstone');
        $this->assertSame('Closed account', $closed->name);
        $this->assertStringEndsWith('@closed.invalid', $closed->email);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($journals, DB::table('ledger_journals')->count());
        $this->assertSame($receipts, DB::table('receipts')->count());
        $this->assertNotNull($ride->fresh());
        $this->assertSame(0, TrustedContact::where('user_id', $passenger->id)->count());
        $this->assertNull(DB::table('payment_methods')->where('user_id', $passenger->id)->value('authorization_code'));
        $this->assertTrue(AuditLog::where('action', 'account.closed')->exists());

        $this->post('/login', ['email' => 'thandi@example.test', 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_closing_a_drivers_account_removes_identity_numbers_and_files_but_keeps_the_blind_index(): void
    {
        Storage::fake('driver-documents');
        [$driver, $profile] = $this->makeVerifiedDriver();
        $profile->forceFill(['id_number' => '8001015009087'])->save();
        $hash = DriverProfile::withoutGlobalScope('user')->find($profile->id)->id_number_hash;
        $document = VerificationDocument::where('user_id', $driver->id)->firstOrFail();
        Storage::disk('driver-documents')->put($document->file_path, 'scan');
        $this->completeTrip(null, $driver, '90.00');

        app(DeleteUser::class)->delete($driver);

        $after = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertNotSame('8001015009087', $after->id_number);
        $this->assertSame($hash, $after->id_number_hash);
        Storage::disk('driver-documents')->assertMissing($document->file_path);
    }

    public function test_an_account_cannot_be_closed_mid_ride(): void
    {
        [$ride, $passenger] = $this->makeActiveRide('in_progress');

        $this->expectException(ValidationException::class);
        app(DeleteUser::class)->delete($passenger);
    }

    public function test_the_data_export_holds_my_data_and_nobody_elses_and_no_secrets(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $other = User::factory()->withPersonalTeam()->create(['name' => 'Someone Else']);
        $this->savedCard($passenger);
        $mine = $this->completeTrip($passenger, null, '120.00');
        $theirs = $this->completeTrip($other, null, '80.00');

        $this->actingAs($passenger)->getJson(route('account.data-export'))->assertStatus(423);

        $response = $this->actingAs($passenger)->withSession([RequireRecentSignIn::SIGNED_IN_AT => time()])->get(route('account.data-export'))->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame([$mine->id], array_column($response->json('rides_as_passenger'), 'id'));
        $this->assertStringNotContainsString('Someone Else', $body);
        $this->assertStringNotContainsString('AUTH_fixture', $body, 'no card authorisation token');
        $this->assertStringNotContainsString((string) $passenger->password, $body);
        $this->assertNotContains($theirs->id, array_column($response->json('rides_as_passenger'), 'id'));
        $this->assertSame('4081', $response->json('saved_cards.0.last4'));
        $this->assertTrue(AuditLog::where('action', 'account.data_exported')->exists());
    }

    public function test_the_retention_schedule_removes_what_is_past_its_period_and_nothing_else(): void
    {
        Storage::fake('driver-documents');
        $user = User::factory()->withPersonalTeam()->create();
        $insertNotification = fn ($createdAt, $readAt) => DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $readAt, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $insertNotification(now()->subDays(400), now()->subDays(399));
        $insertNotification(now()->subDays(5), now()->subDays(4));

        $declined = TrustedContact::create(['user_id' => $user->id, 'name' => 'Old', 'email' => 'old@example.com', 'email_hash' => hash('sha256', 'old@example.com')]);
        DB::table('trusted_contacts')->where('id', $declined->id)->update(['opted_out_at' => now()->subDays(60)]);
        $confirmed = TrustedContact::create(['user_id' => $user->id, 'name' => 'Kept', 'email' => 'kept@example.com', 'email_hash' => hash('sha256', 'kept@example.com')]);
        DB::table('trusted_contacts')->where('id', $confirmed->id)->update(['confirmed_at' => now()->subDays(60)]);

        [, $profile] = $this->makeVerifiedDriver();
        $old = VerificationDocument::where('driver_profile_id', $profile->id)->firstOrFail();
        Storage::disk('driver-documents')->put($old->file_path, 'scan');
        DB::table('verification_documents')->where('id', $old->id)->update(['status' => 'superseded', 'updated_at' => now()->subDays(200)]);

        $this->artisan('ehail:privacy-purge')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        $this->assertNull(TrustedContact::find($declined->id));
        $this->assertNotNull(TrustedContact::find($confirmed->id));
        Storage::disk('driver-documents')->assertMissing($old->file_path);
        $this->assertStringStartsWith('purged/', VerificationDocument::find($old->id)->file_path);
        $this->assertNotSame('superseded', VerificationDocument::where('driver_profile_id', $profile->id)->where('id', '!=', $old->id)->value('status'));
    }

    public function test_the_retention_schedule_is_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ehail:privacy-purge')->assertSuccessful();
    }

    public function test_going_offline_forgets_the_drivers_position(): void
    {
        [$driver, , $vehicle] = $this->makeVerifiedDriver();
        $availability = app(AvailabilityService::class);
        $availability->goOnline($driver, $vehicle);
        DriverAvailability::where('user_id', $driver->id)->update(['last_lat' => -33.9, 'last_lng' => 18.4]);

        $availability->goOffline($driver);

        $this->assertNull(DriverAvailability::where('user_id', $driver->id)->value('last_lat'));
    }

    public function test_operators_see_a_drivers_position_only_on_a_live_ride_and_every_look_is_audited(): void
    {
        [$ride] = $this->makeActiveRide('in_progress');
        $dispatcher = $this->operatorWith(['dispatcher']);

        $this->actingAs($dispatcher)->getJson(route('geo.rides.driver-location', $ride))->assertOk();
        $this->assertTrue(AuditLog::where('actor_user_id', $dispatcher->id)->where('action', 'like', '%driver_position%')->exists());

        DB::table('rides')->where('id', $ride->id)->update(['status' => 'completed', 'completed_at' => now(), 'active_driver_id' => null, 'active_passenger_id' => null]);
        $this->actingAs($dispatcher)->getJson(route('geo.rides.driver-location', $ride))->assertForbidden();
    }

    public function test_the_ride_completed_notice_carries_no_addresses(): void
    {
        [$ride] = $this->makeActiveRide('completed');

        $data = (new RideCompletedNotification($ride))->toDatabase($ride->passenger);

        $this->assertStringNotContainsString('Secret Home Road', $data['message']);
        $this->assertStringNotContainsString('Private Office Park', $data['message']);
    }

    public function test_legacy_identity_documents_are_stripped_streamed_safely_and_audited(): void
    {
        Storage::fake('driver-documents');
        [$driver, $profile] = $this->makeVerifiedDriver();

        $this->actingAs($driver)->post(route('drivers.documents.store'), [
            'type' => 'license',
            'document' => UploadedFile::fake()->createWithContent('licence.jpg', $this->jpegWithExif('GPS-SECRET-MARKER-123')),
        ])->assertRedirect();

        $document = $profile->documents()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('GPS-SECRET-MARKER-123', Storage::disk('driver-documents')->get($document->file_path));

        $reviewer = $this->operatorWith(['verification_reviewer']);
        $response = $this->actingAs($reviewer)->get(route('drivers.documents.show', $document))->assertOk();
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertTrue(AuditLog::where('action', 'driver_document.viewed')->where('actor_user_id', $reviewer->id)->exists());
    }

    public function test_a_sign_in_from_a_new_browser_is_reported_but_the_first_one_is_not(): void
    {
        Notification::fake();
        $user = User::factory()->withPersonalTeam()->create();
        $devices = app(KnownDevices::class);

        $devices->signedIn($user, request());
        Notification::assertNothingSent();

        $devices->signedIn($user, Request::create('/'));
        Notification::assertSentTo($user, SecurityNoticeNotification::class, fn ($n) => $n->kind === 'new_device');
    }
}
