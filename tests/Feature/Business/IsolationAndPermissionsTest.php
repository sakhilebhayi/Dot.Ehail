<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Statements;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * Every business route against every role, and isolation between businesses: one business never sees
 * another's data, a traveller sees only their own rides, a finance viewer can only read.
 */
class IsolationAndPermissionsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    public function test_page_access_by_role(): void
    {
        [$account, $admin] = $this->businessAccount();
        $roles = [
            'admin' => $admin,
            'manager' => $this->member($account, BusinessMember::MANAGER),
            'finance' => $this->member($account, BusinessMember::FINANCE),
            'traveller' => $this->member($account, BusinessMember::TRAVELLER),
        ];

        $matrix = [
            'business.home' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 200],
            'business.trips' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 200],
            'business.approvals' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 200],
            'business.people' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 403],
            'business.policies' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 403],
            'business.invoices' => ['admin' => 200, 'manager' => 403, 'finance' => 200, 'traveller' => 403],
            'business.settings' => ['admin' => 200, 'manager' => 403, 'finance' => 200, 'traveller' => 403],
            'business.book' => ['admin' => 200, 'manager' => 200, 'finance' => 403, 'traveller' => 200],
            'business.trips.csv' => ['admin' => 200, 'manager' => 200, 'finance' => 200, 'traveller' => 200],
            'business.invoices.month-csv' => ['admin' => 200, 'manager' => 403, 'finance' => 200, 'traveller' => 403],
            'ops.business' => ['admin' => 403, 'manager' => 403, 'finance' => 403, 'traveller' => 403],
        ];

        foreach ($matrix as $route => $expected) {
            foreach ($expected as $role => $status) {
                $this->actingAs($roles[$role]->user)->get(route($route))->assertStatus($status);
            }
        }

        $outsider = User::factory()->withPersonalTeam()->create();
        foreach (['business.trips', 'business.people', 'business.invoices', 'business.settings', 'business.book'] as $route) {
            $this->actingAs($outsider)->get(route($route))->assertRedirect(route('business.home'), $route);
        }
        $this->actingAs($outsider)->getJson(route('business.api.profiles'))->assertOk()->assertJsonCount(0, 'profiles');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(route('business.home'))->assertRedirect(route('login'));
    }

    public function test_the_navigation_follows_the_role(): void
    {
        [$account, $admin] = $this->businessAccount();
        $traveller = $this->member($account);

        $this->actingAs($admin->user)->get(route('business.home'))->assertSee(route('business.invoices'))->assertSee(route('business.people'));
        $this->actingAs($traveller->user)->get(route('business.home'))->assertDontSee(route('business.invoices'))->assertDontSee(route('business.people'))->assertSee(route('business.trips'));
    }

    public function test_a_finance_viewer_can_change_nothing(): void
    {
        [$account] = $this->businessAccount();
        $finance = $this->member($account, BusinessMember::FINANCE);
        $traveller = $this->member($account);
        $policy = $account->policies()->first();
        $department = $this->department($account);
        $user = $finance->user;

        $this->actingAs($user)->post(route('business.people.invite'), ['email' => 'x@example.test', 'role' => 'traveller'])->assertForbidden();
        $this->actingAs($user)->patch(route('business.people.update', $traveller->id), ['monthly_allowance' => '1'])->assertForbidden();
        $this->actingAs($user)->delete(route('business.people.destroy', $traveller->id))->assertForbidden();
        $this->actingAs($user)->post(route('business.departments.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->patch(route('business.departments.update', $department->id), ['name' => 'Y'])->assertForbidden();
        $this->actingAs($user)->delete(route('business.departments.destroy', $department->id))->assertForbidden();
        $this->actingAs($user)->post(route('business.policies.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->patch(route('business.policies.update', $policy->id), ['name' => 'Y'])->assertForbidden();
        $this->actingAs($user)->patch(route('business.settings.update'), ['billing_email' => 'a@b.test', 'address_line1' => 'x', 'city' => 'y'])->assertForbidden();
        $this->actingAs($user)->put(route('business.settings.billing'), ['billing_mode' => 'invoice'])->assertForbidden();
        $this->actingAs($user)->post(route('business.approvals.approve', 1))->assertForbidden();

        $this->assertSame('Standard', $policy->fresh()->name);
        $this->assertNull($traveller->fresh()->monthly_allowance_minor);
    }

    public function test_one_business_never_sees_another(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$a, $adminA] = $this->businessAccount();
        [$b, $adminB] = $this->businessAccount(['legal_name' => 'Bongani Builders CC', 'registration_number' => '2001/000002/23']);
        $travellerA = $this->member($a);
        $deptA = $this->department($a, 'Secret Projects');
        $this->requestBusinessRide($travellerA, [], ['purpose' => 'Confidential A trip'])->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'));
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'UTC'));
        $statementA = app(Statements::class)->issue($a->fresh(), '2026-10');

        $as = $this->actingAs($adminB->user);
        $as->get(route('business.trips'))->assertOk()->assertDontSee('Confidential A trip');
        $as->get(route('business.trips', ['person' => $travellerA->id]))->assertOk()->assertDontSee('Confidential A trip');
        $this->assertStringNotContainsString('Confidential A trip', $as->get(route('business.trips.csv'))->getContent());
        $as->get(route('business.people'))->assertOk()->assertDontSee($travellerA->user->email)->assertDontSee('Secret Projects');
        $as->get(route('business.statements.show', $statementA))->assertNotFound();
        $as->get(route('business.statements.csv', $statementA))->assertNotFound();
        $as->patch(route('business.people.update', $travellerA->id), ['monthly_allowance' => '1'])->assertNotFound();
        $as->delete(route('business.people.destroy', $travellerA->id))->assertNotFound();
        $as->patch(route('business.departments.update', $deptA->id), ['name' => 'Taken'])->assertNotFound();
        $as->patch(route('business.policies.update', $a->policies()->first()->id), ['name' => 'Taken'])->assertNotFound();
        $as->get(route('business.invoices'))->assertOk()->assertDontSee($statementA->number);

        // Department and policy ids of another account are refused when assigning.
        $travellerB = $this->member($b);
        $as->patch(route('business.people.update', $travellerB->id), ['department_id' => $deptA->id])->assertSessionHas('error');
        $this->assertNull($travellerB->fresh()->department_id);

        $this->actingAs($adminA->user)->get(route('business.trips'))->assertSee('Confidential A trip');
    }

    public function test_a_traveller_sees_only_their_own_business_rides(): void
    {
        [$account] = $this->businessAccount();
        $alice = $this->member($account);
        $bob = $this->member($account);
        $this->requestBusinessRide($alice, [], ['purpose' => 'Alice visit'])->assertCreated();
        $this->requestBusinessRide($bob, [], ['purpose' => 'Bob visit'])->assertCreated();

        $this->actingAs($alice->user)->get(route('business.trips'))->assertSee('Alice visit')->assertDontSee('Bob visit');
        $this->actingAs($alice->user)->get(route('business.trips', ['person' => $bob->id]))->assertDontSee('Bob visit');
        $csv = $this->actingAs($alice->user)->get(route('business.trips.csv'))->getContent();
        $this->assertStringContainsString('Alice visit', $csv);
        $this->assertStringNotContainsString('Bob visit', $csv);
        $this->actingAs($alice->user)->get(route('business.home'))->assertSee('Alice visit')->assertDontSee('Bob visit');
    }

    public function test_operator_pages_are_for_operators_only(): void
    {
        [$account, $admin] = $this->businessAccount();

        foreach ([['post', 'ops.business.approve'], ['post', 'ops.business.suspend'], ['post', 'ops.business.reactivate'], ['post', 'ops.business.payments'], ['patch', 'ops.business.terms'], ['post', 'ops.business.statements'], ['get', 'ops.business.show']] as [$method, $route]) {
            $this->actingAs($admin->user)->{$method}(route($route, $account->id))->assertForbidden();
        }

        $operator = User::where('is_platform_operator', true)->first();
        $this->actingAs($operator)->get(route('ops.business.show', $account->id))->assertOk()->assertSee($account->registration_number);
        $this->actingAs($operator)->post(route('ops.business.suspend', $account->id), ['note' => 'Fraud check'])->assertRedirect();
        $this->assertSame('suspended', $account->fresh()->status);
        $this->actingAs($operator)->post(route('ops.business.reactivate', $account->id))->assertRedirect();
        $this->assertSame('active', $account->fresh()->status);
    }
}
