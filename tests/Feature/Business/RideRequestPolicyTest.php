<?php

namespace Tests\Feature\Business;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\RiderDetails;
use App\Domain\Business\SpendLimits;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * Policy enforcement at ride request time, through the real ride-request API (dispatch -> the money resolver
 * wrapped by the business resolver). Each refusal rolls the ride back and says why in plain words.
 */
class RideRequestPolicyTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_an_allowed_ride_is_booked_on_the_account_and_holds_the_fare(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['monthly_allowance_minor' => 50_000]);

        $response = $this->requestBusinessRide($traveller, ['totalMinor' => 8500], ['purpose' => 'Client visit']);
        $response->assertCreated();

        $rideId = $response->json('ride.id') ?? Ride::latest('id')->value('id');
        $trip = BusinessTrip::where('ride_id', $rideId)->sole();
        $this->assertSame(BusinessTrip::BOOKED, $trip->status);
        $this->assertSame(8500, $trip->reserved_minor);
        $this->assertSame('Client visit', $trip->purpose);
        $this->assertSame(RidePaymentChoice::BUSINESS_ACCOUNT, RidePaymentChoice::where('ride_id', $rideId)->sole()->method);
        $this->assertSame($account->id, (int) RidePaymentChoice::where('ride_id', $rideId)->sole()->business_account_id);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
     */
    public static function refusals(): array
    {
        return [
            'category not allowed' => [['allowed_categories' => ['standard']], ['category' => 'premium'], [], 'business_category_not_allowed'],
            'fare above maximum' => [['max_fare_minor' => 5000], ['totalMinor' => 8500], [], 'business_fare_above_maximum'],
            'purpose required' => [['purpose_required' => true], [], [], 'business_purpose_required'],
            'day not allowed' => [['allowed_days' => [6, 7]], [], [], 'business_day_not_allowed'],
            'outside hours' => [['hours_start' => 0, 'hours_end' => 60], [], [], 'business_time_not_allowed'],
            'approval needed' => [['approval_above_minor' => 5000], ['totalMinor' => 8500], [], 'business_approval_required'],
            'guest without phone' => [['guest_bookings' => true], [], ['guest_name' => 'Visitor'], 'business_guest_details_missing'],
            'guest not allowed' => [[], [], ['guest_name' => 'Visitor', 'guest_phone' => '+27 82 000 1111'], 'business_guest_not_allowed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $business
     */
    #[DataProvider('refusals')]
    public function test_the_policy_refuses_at_request_time_and_nothing_is_created(array $policy, array $quote, array $business, string $code): void
    {
        // Wednesday 2026-11-11 10:00 in Johannesburg.
        Carbon::setTestNow(Carbon::parse('2026-11-11 08:00:00', 'UTC'));
        [$account] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['policy_id' => $this->policy($account, $policy)->id]);

        $response = $this->requestBusinessRide($traveller, $quote, $business);

        $response->assertStatus(422)->assertJsonPath('error', $code);
        $this->assertNotEmpty($response->json('message'));
        $this->assertSame(0, Ride::count(), 'the ride was rolled back');
        $this->assertSame(0, BusinessTrip::whereNotNull('ride_id')->count());
    }

    public function test_overnight_hours_wrap_past_midnight(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['policy_id' => $this->policy($account, ['hours_start' => 22 * 60, 'hours_end' => 6 * 60])->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-11 21:30:00', 'UTC')); // 23:30 local
        $this->requestBusinessRide($traveller)->assertCreated();

        $this->completeBusinessRide(Ride::latest('id')->value('id'));
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC')); // 12:00 local
        $this->requestBusinessRide($traveller)->assertStatus(422)->assertJsonPath('error', 'business_time_not_allowed');
    }

    public function test_service_area_geofence(): void
    {
        $capeTown = ServiceArea::create([
            'slug' => 'cape-town', 'name' => 'Cape Town', 'country_code' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'polygon' => [[-34.2, 18.3], [-33.7, 18.3], [-33.7, 18.8], [-34.2, 18.8]], 'is_active' => true,
        ]);
        $joburg = ServiceArea::create([
            'slug' => 'joburg', 'name' => 'Johannesburg', 'country_code' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'polygon' => [[-26.4, 27.8], [-25.9, 27.8], [-25.9, 28.3], [-26.4, 28.3]], 'is_active' => true,
        ]);
        [$account] = $this->businessAccount();
        $inCapeTown = $this->member($account, BusinessMember::TRAVELLER, ['policy_id' => $this->policy($account, ['service_area_ids' => [$capeTown->id]])->id]);
        $inJoburg = $this->member($account, BusinessMember::TRAVELLER, ['policy_id' => $this->policy($account, ['service_area_ids' => [$joburg->id]])->id]);

        $this->requestBusinessRide($inCapeTown)->assertCreated();
        $this->requestBusinessRide($inJoburg)->assertStatus(422)->assertJsonPath('error', 'business_area_not_allowed');
    }

    public function test_allowance_department_budget_and_credit_limit_are_enforced(): void
    {
        [$account] = $this->businessAccount([], 20_000);
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['monthly_allowance_minor' => 10_000]);

        $this->requestBusinessRide($traveller, ['totalMinor' => 8000])->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '80.00');
        $this->requestBusinessRide($traveller, ['totalMinor' => 3000])->assertStatus(422)->assertJsonPath('error', 'business_allowance_exceeded');

        $sales = $this->department($account, 'Sales', 5000);
        $seller = $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $sales->id]);
        $this->requestBusinessRide($seller, ['totalMinor' => 6000])->assertStatus(422)->assertJsonPath('error', 'business_budget_exceeded');

        // Credit: R200 limit, R80 owed, R150 more would pass it.
        $rich = $this->member($account, BusinessMember::TRAVELLER);
        $this->requestBusinessRide($rich, ['totalMinor' => 15_000])->assertStatus(422)->assertJsonPath('error', 'business_credit_limit');
        $this->requestBusinessRide($rich, ['totalMinor' => 11_000])->assertCreated();
    }

    public function test_a_pending_or_suspended_account_cannot_take_new_rides(): void
    {
        [$account, $admin] = $this->businessAccount();
        $traveller = $this->member($account);

        $account->forceFill(['status' => BusinessAccount::SUSPENDED, 'status_reason' => 'overdue'])->save();
        $this->requestBusinessRide($traveller)->assertStatus(422)->assertJsonPath('error', 'business_account_suspended');

        $account->forceFill(['status' => BusinessAccount::PENDING, 'status_reason' => null])->save();
        $this->requestBusinessRide($admin)->assertStatus(422)->assertJsonPath('error', 'business_account_pending');
    }

    public function test_finance_viewers_and_removed_members_have_no_business_profile(): void
    {
        [$account] = $this->businessAccount();
        $finance = $this->member($account, BusinessMember::FINANCE);
        $this->assertNull($finance->payment_method_id);

        $this->actingAs($finance->user)->getJson(route('business.api.profiles'))->assertOk()->assertJsonCount(0, 'profiles');

        $traveller = $this->member($account);
        $profile = $traveller->payment_method_id;
        $traveller->forceFill(['status' => BusinessMember::REMOVED])->save();

        $issued = $this->quotes->issue($traveller->user);
        $this->actingAs($traveller->user)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $issued->id, 'payment_method' => 'business_account', 'payment_ref' => (string) $profile])
            ->assertStatus(422)->assertJsonPath('error', 'business_profile_unknown');
    }

    public function test_someone_cannot_use_another_persons_business_profile(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $stranger = $this->passenger();
        $issued = $this->quotes->issue($stranger);

        $this->actingAs($stranger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $issued->id, 'payment_method' => 'business_account', 'payment_ref' => (string) $traveller->payment_method_id])
            ->assertStatus(422)->assertJsonPath('error', 'business_profile_unknown');
        $this->assertSame(0, Ride::count());
    }

    public function test_managers_book_for_travellers_and_guests_travellers_cannot_book_for_others(): void
    {
        [$account] = $this->businessAccount();
        $manager = $this->member($account, BusinessMember::MANAGER);
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['monthly_allowance_minor' => 20_000]);
        $other = $this->member($account, BusinessMember::TRAVELLER);

        $this->requestBusinessRide($manager, ['totalMinor' => 9000], ['traveller_member_id' => $traveller->id, 'rider_phone' => '+27 82 555 0101'])->assertCreated();
        $trip = BusinessTrip::latest('id')->first();
        $this->assertSame($traveller->id, $trip->traveller_member_id);
        $this->assertSame($traveller->user->name, $trip->rider_name);
        $this->assertSame(9000, app(SpendLimits::class)->used('member', $traveller->id, BusinessClock::monthKey()), 'counts against the traveller allowance');
        $this->completeBusinessRide($trip->ride_id);

        $this->requestBusinessRide($manager, [], ['guest_name' => 'Ama Mensah', 'guest_phone' => '+27 82 123 4567'])->assertCreated();
        $guestTrip = BusinessTrip::latest('id')->first();
        $this->assertTrue($guestTrip->is_guest);
        $this->assertSame('•••• 4567', $guestTrip->maskedRiderPhone());
        $this->assertNotSame('+27 82 123 4567', DB::table('business_trips')->where('id', $guestTrip->id)->value('rider_phone'), 'stored encrypted');
        $this->assertSame(['on_behalf' => true, 'guest' => true, 'name' => 'Ama Mensah', 'phone' => '+27 82 123 4567', 'account' => 'Thandeka Logistics (Pty) Ltd'], app(RiderDetails::class)->forRide($guestTrip->ride_id));

        $this->requestBusinessRide($traveller, [], ['traveller_member_id' => $other->id])->assertStatus(422)->assertJsonPath('error', 'business_cannot_book_for_others');
    }

    public function test_an_existing_ride_cannot_be_moved_onto_the_business_account(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $issued = $this->quotes->issue($traveller->user);
        $this->actingAs($traveller->user)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $issued->id, 'payment_method' => 'cash'])->assertCreated();
        $ride = Ride::latest('id')->first();

        $this->actingAs($traveller->user)->putJson(route('money.rides.choice', $ride), ['method' => 'business_account', 'payment_method_id' => $traveller->payment_method_id])
            ->assertUnprocessable();
        $this->assertSame('cash', RidePaymentChoice::where('ride_id', $ride->id)->value('method'));
    }

    public function test_profiles_and_prepare_report_plain_reasons_before_requesting(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER, [
            'monthly_allowance_minor' => 30_000,
            'policy_id' => $this->policy($account, ['purpose_required' => true, 'approval_above_minor' => 20_000, 'allowed_categories' => ['standard']])->id,
        ]);
        $user = $traveller->user;

        $this->actingAs($user)->getJson(route('business.api.profiles'))->assertOk()
            ->assertJsonPath('profiles.0.id', $traveller->payment_method_id)
            ->assertJsonPath('profiles.0.account.name', 'Thandeka Logistics (Pty) Ltd')
            ->assertJsonPath('profiles.0.account.bookable', true)
            ->assertJsonPath('profiles.0.needs_purpose', true)
            ->assertJsonPath('profiles.0.approval_above_minor', 20_000)
            ->assertJsonPath('profiles.0.allowance.left_minor', 30_000)
            ->assertJsonPath('profiles.0.can_book_for_others', false);

        $quote = $this->quotes->issue($user, ['totalMinor' => 25_000, 'category' => 'premium']);
        $this->actingAs($user)->postJson(route('business.api.prepare'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id])->assertOk()
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('needs_approval', false)
            ->assertJsonPath('reasons.0.code', 'category_not_allowed')
            ->assertJsonPath('reasons.1.code', 'purpose_required');

        $quote = $this->quotes->issue($user, ['totalMinor' => 25_000]);
        $this->actingAs($user)->postJson(route('business.api.prepare'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id, 'purpose' => 'Airport'])->assertOk()
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('needs_approval', true)
            ->assertJsonPath('reasons.0.code', 'approval_required')
            ->assertJsonPath('request.payment_method', 'business_account');

        // Prepared details (the purpose) travel with the quote: the request itself needs no business object.
        $quote = $this->quotes->issue($user, ['totalMinor' => 9000]);
        $this->actingAs($user)->postJson(route('business.api.prepare'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id, 'purpose' => 'Client lunch'])->assertOk()->assertJsonPath('allowed', true);
        $this->actingAs($user)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $quote->id, 'payment_method' => 'business_account', 'payment_ref' => (string) $traveller->payment_method_id])->assertCreated();
        $this->assertSame('Client lunch', BusinessTrip::whereNotNull('ride_id')->sole()->purpose);

        $this->actingAs($this->passenger())->postJson(route('business.api.prepare'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id])
            ->assertNotFound()->assertJsonPath('error', 'business_profile_unknown');
    }
}
