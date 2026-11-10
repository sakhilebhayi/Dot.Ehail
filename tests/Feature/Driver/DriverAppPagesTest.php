<?php

namespace Tests\Feature\Driver;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

/**
 * The driver app's pages (/drive/*): what each kind of driver sees, and that a driver only ever sees their own
 * offers, trips, earnings and payouts.
 */
class DriverAppPagesTest extends TestCase
{
    use CreatesEhailFixtures, CreatesSafetyFixtures, RefreshDatabase;

    private function xpath(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());

        return new DOMXPath($dom);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/drive', '/drive/onboarding', '/drive/documents', '/drive/earnings', '/drive/earnings/payouts', '/drive/trips', '/drive/safety', '/drive/account'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_someone_not_registered_sees_the_way_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->xpath($this->actingAs($user)->get('/drive')->assertOk()->assertSee('Not registered to drive'));
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('drive.apply').'"]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);

        // Pages that need a profile send them to apply.
        foreach (['/drive/onboarding', '/drive/documents', '/drive/earnings', '/drive/trips'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('drive.apply'));
        }
    }

    public function test_a_pending_applicant_sees_the_checklist_and_why_they_cannot_go_online(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'pending', 'is_online' => false]);
        \App\Models\Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

        $this->actingAs($user)->get('/drive')
            ->assertOk()
            ->assertSee('Application in review')
            ->assertSee('You can go online when:')
            ->assertSee('0 of 11 approved');
    }

    public function test_a_verified_driver_gets_the_go_online_control_and_an_unverified_one_gets_the_reasons(): void
    {
        [$verified] = $this->makeVerifiedDriver();
        $xpath = $this->xpath($this->actingAs($verified)->get('/drive')->assertOk()->assertSee('You are offline'));
        $this->assertSame(1, $xpath->query('//button[@data-dr-go-online][not(@disabled)]')->length, 'go online is offered');
        $this->assertSame(1, $xpath->query('//script[@id="dr-boot"]')->length, 'the console boots from the dispatch feed');
        $boot = json_decode($xpath->query('//script[@id="dr-boot"]')->item(0)->textContent, true);
        $this->assertSame(route('ehail.dispatch.driver.feed'), $boot['urls']['feed']);
        $this->assertSame('offline', $boot['feed']['availability']['state']);

        $unverified = $this->makeApprovedDriver(online: false);
        $xpath = $this->xpath($this->actingAs($unverified)->get('/drive')->assertOk()->assertSee('You can go online when:'));
        $this->assertSame(0, $xpath->query('//button[@data-dr-go-online]')->length, 'no live control while the gate says no');
        $this->assertSame(1, $xpath->query('//button[contains(@class,"dr-golive")][@disabled]')->length);
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('drive.documents').'"]')->length, 'the fix is one tap away');
    }

    public function test_the_documents_checklist_shows_each_state_with_the_reviewers_reason(): void
    {
        [$user, $profile, $vehicle] = $this->makeVerifiedDriver();
        VerificationDocument::query()->where('driver_profile_id', $profile->id)->where('type', 'prdp')->delete();
        VerificationDocument::query()->where('driver_profile_id', $profile->id)->where('type', 'insurance')->update(['status' => DocumentStatus::Rejected, 'reject_reason' => 'illegible']);
        VerificationDocument::query()->where('driver_profile_id', $profile->id)->where('type', 'roadworthy_certificate')->update(['status' => DocumentStatus::Pending]);
        VerificationDocument::query()->where('driver_profile_id', $profile->id)->where('type', 'drivers_licence')->update(['expires_on' => now()->addDays(10)->toDateString()]);

        $response = $this->actingAs($user)->get(route('drive.documents'))->assertOk();
        $response->assertSee('Missing')->assertSee('Rejected')->assertSee('The document cannot be read')->assertSee('Send again')
            ->assertSee('Submitted')->assertSee('Expiring soon')->assertSee('capture="environment"', false);
        $xpath = $this->xpath($response);
        $this->assertGreaterThan(0, $xpath->query('//form[@data-dr-upload][@action="'.route('drive.documents.upload').'"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[@data-dr-typing]')->length, 'uploads are marked as typing-heavy (held while moving)');
    }

    public function test_upload_goes_through_the_verification_service_as_json_or_a_plain_form(): void
    {
        Storage::fake('driver-documents');
        [$user, $profile] = $this->makeVerifiedDriver();

        $this->actingAs($user)->postJson(route('drive.documents.upload'), [
            'type' => 'profile_photo',
            'document' => $this->jpegUpload(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->actingAs($user)->post(route('drive.documents.upload'), [
            'type' => 'drivers_licence',
            'expires_on' => now()->addYear()->toDateString(),
            'document_photo' => $this->jpegUpload(),
        ])->assertRedirect(route('drive.documents'));

        $this->assertSame(2, VerificationDocument::query()->where('driver_profile_id', $profile->id)->where('status', DocumentStatus::Pending)->count());

        // A document type that does not exist is refused by the service.
        $this->actingAs($user)->postJson(route('drive.documents.upload'), ['type' => 'nope', 'document' => $this->jpegUpload()])->assertUnprocessable();
    }

    public function test_earnings_show_only_the_drivers_own_rows(): void
    {
        [$me] = $this->makeVerifiedDriver();
        [$other] = $this->makeVerifiedDriver();
        $this->earning($me, 12345, 3000, 9345);
        $this->earning($other, 99999, 20000, 79999);

        $this->actingAs($me)->get(route('drive.earnings'))
            ->assertOk()
            ->assertSee('R93.45')
            ->assertSee('R123.45')
            ->assertDontSee('R799.99')
            ->assertSee('No active programmes')
            ->assertSee('Time online is not shown because it is not measured yet.');

        $this->actingAs($me)->get(route('drive.earnings', ['period' => 'week']))->assertOk()->assertSee('Week of');
        $this->actingAs($me)->get(route('drive.earnings', ['period' => 'year']))->assertSessionHasErrors('period');
    }

    public function test_payouts_show_own_payouts_with_their_composition_and_hide_others(): void
    {
        [$me] = $this->makeVerifiedDriver();
        [$other] = $this->makeVerifiedDriver();
        $mine = $this->payout($me, 50000);
        $theirs = $this->payout($other, 70000);

        $this->actingAs($me)->get(route('drive.earnings.payouts'))->assertOk()->assertSee('R500.00')->assertDontSee('R700.00');
        $this->actingAs($me)->get(route('drive.earnings.payout', $mine))->assertOk()->assertSee($mine->reference);
        $this->actingAs($me)->get(route('drive.earnings.payout', $theirs))->assertNotFound();
    }

    public function test_bank_account_is_saved_masked_and_verification_is_honest_without_paystack(): void
    {
        [$me] = $this->makeVerifiedDriver();

        $this->actingAs($me)->post(route('drive.earnings.bank-account'), [
            'account_holder' => 'T Mokoena', 'account_number' => '62 1234 5678', 'bank_code' => '250655', 'bank_name' => 'FNB', 'account_type' => 'personal',
        ])->assertRedirect(route('drive.earnings.payouts'));

        $account = PayoutAccount::query()->where('user_id', $me->id)->firstOrFail();
        $this->assertSame('driver', $account->payee_type);

        $this->actingAs($me)->get(route('drive.earnings.payouts'))->assertOk()->assertSee('FNB')->assertSee('5678')->assertDontSee('6212345678')
            ->assertSee('Automatic bank checks are not set up');

        $this->actingAs($me)->post(route('drive.earnings.bank-account.verify', $account), ['document_type' => 'identityNumber', 'document_number' => '8001015009087'])
            ->assertRedirect()->assertSessionHas('status', 'Automatic checking is not available; operations checks the account before paying.');

        [$other] = $this->makeVerifiedDriver();
        $this->actingAs($other)->post(route('drive.earnings.bank-account.verify', $account), ['document_type' => 'identityNumber', 'document_number' => '1'])->assertNotFound();
    }

    public function test_trips_list_and_detail_are_the_drivers_own(): void
    {
        [$me] = $this->makeVerifiedDriver();
        [$other] = $this->makeVerifiedDriver();
        $mine = $this->makeRide(['driver_id' => $me->id, 'status' => 'completed', 'completed_at' => now(), 'dropoff_address' => 'Mine Street 1', 'quoted_total_minor' => 8500, 'final_fare_minor' => 9000, 'currency' => 'ZAR']);
        $theirs = $this->makeRide(['driver_id' => $other->id, 'status' => 'completed', 'completed_at' => now(), 'dropoff_address' => 'Theirs Road 2']);

        $this->actingAs($me)->get(route('drive.trips'))->assertOk()->assertSee('Mine Street 1')->assertDontSee('Theirs Road 2');
        $this->actingAs($me)->get(route('drive.trips.show', $mine))->assertOk()
            ->assertSee('Fare agreed at request')->assertSee('R85.00')->assertSee('R90.00')->assertSee('Rate the passenger')->assertSee('Report the passenger');
        $this->actingAs($me)->get(route('drive.trips.show', $theirs))->assertNotFound();
    }

    public function test_cash_opt_in_is_the_money_domains_setting(): void
    {
        config(['money.cash.enabled' => true]);
        [$me] = $this->makeVerifiedDriver();

        $this->actingAs($me)->get(route('drive.account'))->assertOk()->assertSee('Accept cash trips');
        $this->actingAs($me)->post(route('drive.account.cash'), ['accepts_cash' => 1])->assertRedirect(route('drive.account'));
        $this->assertTrue((bool) DriverMoneySetting::query()->where('user_id', $me->id)->value('accepts_cash'));
    }

    public function test_safety_page_states_limits_and_lists_only_own_support_cases(): void
    {
        [$me] = $this->makeVerifiedDriver();
        $this->actingAs($me)->get(route('drive.safety'))->assertOk()
            ->assertSee('Dot.Ehail does not dispatch police or ambulances')
            ->assertSee('tel:10111', false)->assertSee('tel:10177', false)->assertSee('tel:112', false)
            ->assertDontSee('data-dr-page-sos', false);

        // On a trip, SOS is offered with the 3-second cancel.
        $ride = $this->makeRide(['driver_id' => $me->id, 'active_driver_id' => $me->id, 'status' => 'in_progress', 'started_at' => now()]);
        $this->actingAs($me)->get(route('drive.safety'))->assertOk()->assertSee(route('safety.rides.sos', $ride), false)->assertSee('Sending SOS in');
    }

    public function test_applying_independently_registers_a_pending_one_driver_fleet_and_continues_to_onboarding(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Dlamini']);

        $this->actingAs($user)->get(route('drive.apply'))->assertOk()->assertSee('Drive independently');

        $this->actingAs($user)->post(route('drive.apply.store'), [
            'mode' => 'independent',
            'license_number' => 'ZA-LIC-1',
            'id_number' => '9001015009087',
            'existing_fleet_id' => '',
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla', 'vehicle_year' => 2020, 'vehicle_color' => 'White',
            'vehicle_plate_number' => 'CA 100 200', 'vehicle_type' => 'standard',
        ])->assertRedirect(route('drive.onboarding'));

        $fleet = Fleet::query()->where('owner_user_id', $user->id)->firstOrFail();
        $this->assertSame('Lerato Dlamini (independent)', $fleet->name);
        $this->assertSame(Fleet::STATUS_PENDING, $fleet->status);
        $this->actingAs($user)->get(route('drive.onboarding'))->assertOk()->assertSee('Application in review');
    }

    public function test_the_legacy_vehicle_pages_now_render_in_the_driver_shell(): void
    {
        // Moved from ResponsiveShellTest: /drive/vehicles left the legacy drawer shell for the driver app shell.
        $driver = $this->makeApprovedDriver();

        $xpath = $this->xpath($this->actingAs($driver)->get(route('drivers.vehicles.index'))->assertOk());
        $this->assertSame(1, $xpath->query('//*[@data-surface="driver"]')->length);
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"eh-tabbar")]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
    }

    public function test_the_driver_pages_allow_geolocation_for_this_origin_only(): void
    {
        [$me] = $this->makeVerifiedDriver();

        $this->actingAs($me)->get('/drive')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()');
    }

    private function earning(User $driver, int $fare, int $commission, int $net): DriverEarning
    {
        static $n = 0;
        $n++;

        return DriverEarning::query()->create([
            'kind' => DriverEarning::KIND_TRIP, 'source_key' => 'test:'.$n.':'.$driver->id, 'driver_id' => $driver->id,
            'payee_type' => 'driver', 'payee_user_id' => $driver->id, 'currency' => 'ZAR',
            'fare_minor' => $fare, 'commission_minor' => $commission, 'deductions_minor' => $commission, 'net_minor' => $net,
            'status' => DriverEarning::AVAILABLE, 'earned_at' => now(),
        ]);
    }

    private function payout(User $driver, int $amount): Payout
    {
        static $weeks = 0;
        $batch = PayoutBatch::query()->create(['currency' => 'ZAR', 'period_end' => now()->subWeeks(++$weeks)->toDateString()]);

        return Payout::query()->create([
            'payout_batch_id' => $batch->id, 'payee_user_id' => $driver->id, 'payee_type' => 'driver', 'currency' => 'ZAR',
            'earnings_minor' => $amount, 'cash_offset_minor' => 0, 'amount_minor' => $amount,
            'composition' => ['earning_ids' => [], 'trips' => 0], 'status' => Payout::SCHEDULED, 'reference' => 'ehpo-test-'.$driver->id,
        ]);
    }
}
