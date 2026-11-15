<?php

namespace Tests\Feature\Search;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * R-52 header search, business surface: the current account's trips only. Another business's trips never appear, a
 * traveller sees only the trips they took or booked, no phone number or e-mail is printed, and the portal's own trips
 * page takes the same text (?q=) through the same rule.
 */
class BusinessHeaderSearchTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    private function trip(BusinessAccount $account, BusinessMember $booker, string $pickup, ?BusinessMember $traveller = null, array $attributes = []): BusinessTrip
    {
        $ride = Ride::create(['passenger_id' => ($traveller ?? $booker)->user_id, 'pickup_address' => $pickup, 'dropoff_address' => 'Airport', 'status' => 'completed', 'vehicle_type' => 'standard', 'estimated_fare' => 80, 'requested_at' => now()]);

        return BusinessTrip::forceCreate(array_merge([
            'business_account_id' => $account->id, 'booker_member_id' => $booker->id, 'traveller_member_id' => $traveller?->id,
            'ride_id' => $ride->id, 'status' => BusinessTrip::BOOKED, 'booked_at' => now(), 'department_name' => 'Sales', 'cost_centre' => 'CC-SAL',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function suggest(User $user, string $q): array
    {
        return $this->actingAs($user)->getJson(route('search.suggest', ['q' => $q, 'scope' => 'business']))->assertOk()->json();
    }

    private function labels(array $json): string
    {
        return collect($json['groups'])->reject(fn ($g) => $g['key'] === 'all')->flatMap(fn ($g) => $g['rows'])->pluck('label')->implode(' | ');
    }

    public function test_one_business_never_sees_another_businesss_trips(): void
    {
        [$acme, $acmeAdmin] = $this->businessAccount(['legal_name' => 'Acme Freight']);
        [$globex, $globexAdmin] = $this->businessAccount(['legal_name' => 'Globex Freight', 'registration_number' => '2020/222222/07']);
        $this->trip($acme, $acmeAdmin, '1 Acme Road, Foreshore');
        $this->trip($globex, $globexAdmin, '2 Globex Road, Foreshore');

        $this->assertStringContainsString('1 Acme Road', $this->labels($this->suggest($acmeAdmin->user, 'foreshore')));
        $this->assertStringNotContainsString('Globex', $this->labels($this->suggest($acmeAdmin->user, 'foreshore')));
        $this->assertStringNotContainsString('Acme', $this->labels($this->suggest($globexAdmin->user, 'foreshore')));
        $this->assertSame('', $this->labels($this->suggest($acmeAdmin->user, 'globex')));
    }

    public function test_a_traveller_finds_only_their_own_trips_and_a_manager_finds_the_whole_account(): void
    {
        [$account, $admin] = $this->businessAccount();
        $anna = $this->member($account, BusinessMember::TRAVELLER);
        $ben = $this->member($account, BusinessMember::TRAVELLER);
        $manager = $this->member($account, BusinessMember::MANAGER);
        $this->trip($account, $anna, '11 Annas Street', $anna);
        $this->trip($account, $ben, '22 Bens Street', $ben);

        $this->assertStringContainsString('11 Annas Street', $this->labels($this->suggest($anna->user, 'street')));
        $this->assertStringNotContainsString('22 Bens Street', $this->labels($this->suggest($anna->user, 'street')));

        $all = $this->labels($this->suggest($manager->user, 'street'));
        $this->assertStringContainsString('11 Annas Street', $all);
        $this->assertStringContainsString('22 Bens Street', $all);
    }

    public function test_the_text_matches_department_cost_centre_purpose_and_ride_number_but_never_prints_a_phone(): void
    {
        [$account, $admin] = $this->businessAccount();
        $trip = $this->trip($account, $admin, '5 Quiet Lane', null, ['purpose' => 'Client site visit', 'department_name' => 'Logistics', 'cost_centre' => 'CC-LOG', 'rider_name' => 'Guest Visitor', 'is_guest' => true, 'rider_phone' => '+27821234567']);

        foreach (['logistics', 'cc-log', 'client site', '#'.$trip->ride_id, 'guest visitor'] as $q) {
            $json = $this->suggest($admin->user, $q);
            $this->assertStringContainsString('5 Quiet Lane', $this->labels($json), $q);
            $this->assertStringNotContainsString('0821234567', json_encode($json));
            $this->assertStringNotContainsString('821234567', json_encode($json));
        }
        $this->assertSame(route('business.trips', ['q' => '#'.$trip->ride_id]), $this->suggest($admin->user, 'logistics')['groups'][0]['rows'][0]['href']);
    }

    public function test_a_travellers_search_does_not_match_a_guest_riders_name_they_cannot_see(): void
    {
        [$account, $admin] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER);
        $this->trip($account, $traveller, '8 Plain Road', $traveller, ['rider_name' => 'Hidden Guest']);

        $this->assertSame('', $this->labels($this->suggest($traveller->user, 'hidden guest')));
        $this->assertStringContainsString('8 Plain Road', $this->labels($this->suggest($admin->user, 'hidden guest')));
    }

    public function test_the_portals_trips_page_takes_the_same_text_through_the_same_rule(): void
    {
        [$account, $admin] = $this->businessAccount();
        $other = $this->member($account, BusinessMember::TRAVELLER);
        $this->trip($account, $admin, '31 Harbour Road');
        $this->trip($account, $other, '32 Mountain Road', $other);

        $page = $this->actingAs($admin->user)->get(route('business.trips', ['q' => 'harbour']))->assertOk();
        $page->assertSee('31 Harbour Road')->assertDontSee('32 Mountain Road');
        $this->actingAs($other->user)->get(route('business.trips', ['q' => 'harbour']))->assertOk()->assertDontSee('31 Harbour Road');
    }
}
