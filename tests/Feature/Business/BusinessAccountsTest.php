<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Notifications\MemberInvitedNotification;
use App\Domain\Payments\Models\PaymentMethod;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * Opening an account (application -> operator review), linking an InfoDot company team, inviting people
 * by email and accepting, roles and the last-admin rule.
 */
class BusinessAccountsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Sizwe Engineering (Pty) Ltd', 'registration_number' => '2015/987654/07', 'vat_number' => '4123456789',
            'billing_email' => 'Finance@Sizwe.example', 'address_line1' => '12 Main Road', 'city' => 'Durban', 'country' => 'ZA', 'billing_mode' => 'invoice',
        ], $overrides);
    }

    public function test_apply_wait_for_review_then_an_operator_approves_with_terms(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('business.home'))->assertOk()->assertSee('Business accounts open here');

        $this->actingAs($user)->post(route('business.apply'), $this->application())->assertRedirect(route('business.home'));
        $account = BusinessAccount::sole();
        $this->assertSame(BusinessAccount::PENDING, $account->status);
        $this->assertSame('finance@sizwe.example', $account->billing_email);
        $this->assertSame('ZAR', $account->currency);
        $admin = BusinessMember::sole();
        $this->assertSame(BusinessMember::ADMIN, $admin->role);
        $this->assertSame(PaymentMethod::BUSINESS_ACCOUNT, $admin->paymentMethod->type);
        $this->assertTrue($account->policies()->sole()->is_default);

        $this->actingAs($user)->get(route('business.home'))->assertOk()->assertSee('Waiting for review');
        $this->actingAs($user)->get(route('ops.business'))->assertForbidden();

        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();
        $this->actingAs($operator)->get(route('ops.business'))->assertOk()->assertSee('Sizwe Engineering');
        $this->actingAs($operator)->post(route('ops.business.approve', $account->id), ['credit_limit' => '25000', 'terms_days' => 30, 'grace_days' => 7])->assertRedirect();

        $account->refresh();
        $this->assertSame(BusinessAccount::ACTIVE, $account->status);
        $this->assertSame(2_500_000, $account->credit_limit_minor);
        $this->assertSame($operator->id, $account->reviewed_by);
    }

    public function test_an_application_can_link_only_a_company_team_the_applicant_runs(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Team::forceCreate(['user_id' => $user->id, 'name' => 'Sizwe Engineering', 'personal_team' => false, 'infodot_team_uuid' => 'd6a1c1f0-0000-4000-8000-000000000001']);
        $someoneElses = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'personal_team' => false]);
        $someoneElses->users()->attach($user->id, ['role' => 'editor']);

        $this->actingAs($user)->post(route('business.apply'), $this->application(['team_id' => $someoneElses->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, BusinessAccount::count());

        $this->actingAs($user)->post(route('business.apply'), $this->application(['team_id' => $company->id]))->assertRedirect(route('business.home'));
        $this->assertSame($company->id, BusinessAccount::sole()->team_id);

        $second = User::factory()->withPersonalTeam()->create();
        $company->users()->attach($second->id, ['role' => 'admin']);
        $this->actingAs($second)->post(route('business.apply'), $this->application(['team_id' => $company->id]))->assertSessionHas('error');
        $this->assertSame(1, BusinessAccount::count());
    }

    public function test_invite_by_email_and_accept_with_the_same_email_only(): void
    {
        [$account, $admin] = $this->businessAccount();
        $sales = $this->department($account, 'Sales');

        $this->actingAs($admin->user)->post(route('business.people.invite'), ['email' => 'Naledi@Thandeka.example', 'name' => 'Naledi', 'role' => 'traveller', 'department_id' => $sales->id, 'monthly_allowance' => '1500'])
            ->assertRedirect()->assertSessionHas('success');

        $invite = BusinessMember::where('email', 'naledi@thandeka.example')->sole();
        $this->assertSame(BusinessMember::INVITED, $invite->status);
        $this->assertSame(150_000, $invite->monthly_allowance_minor);
        $token = null;
        Notification::assertSentOnDemand(MemberInvitedNotification::class, function (MemberInvitedNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertSame(hash('sha256', $token), $invite->invite_token_hash);

        $wrong = User::factory()->withPersonalTeam()->create(['email' => 'someone@else.example']);
        $this->actingAs($wrong)->post(route('business.invitations.accept', $token))->assertSessionHas('error');
        $this->assertSame(BusinessMember::INVITED, $invite->fresh()->status);

        $naledi = User::factory()->withPersonalTeam()->create(['email' => 'naledi@thandeka.example']);
        $this->actingAs($naledi)->get(route('business.invitations.show', $token))->assertOk()->assertSee('Thandeka Logistics');
        $this->actingAs($naledi)->post(route('business.invitations.accept', $token))->assertRedirect(route('business.home'));
        $invite->refresh();
        $this->assertSame(BusinessMember::ACTIVE, $invite->status);
        $this->assertSame($naledi->id, $invite->user_id);
        $this->assertNull($invite->invite_token_hash);
        $this->assertSame($naledi->id, $invite->paymentMethod->user_id);

        $this->actingAs($naledi)->post(route('business.invitations.accept', $token))->assertNotFound();
    }

    public function test_an_invitation_listed_in_the_portal_can_be_accepted_and_expired_ones_cannot(): void
    {
        [$account, $admin] = $this->businessAccount();
        $this->actingAs($admin->user)->post(route('business.people.invite'), ['email' => 'sipho@example.test', 'role' => 'traveller'])->assertSessionHas('success');
        $invite = BusinessMember::where('email', 'sipho@example.test')->sole();
        $sipho = User::factory()->withPersonalTeam()->create(['email' => 'sipho@example.test']);

        $this->actingAs($sipho)->get(route('business.home'))->assertSee('Thandeka Logistics');

        $invite->forceFill(['invite_expires_at' => now()->subMinute()])->save();
        $this->actingAs($sipho)->post(route('business.invitations.accept-listed', $invite->id))->assertSessionHas('error');

        $invite->forceFill(['invite_expires_at' => now()->addDay()])->save();
        $this->actingAs($sipho)->post(route('business.invitations.accept-listed', $invite->id))->assertRedirect(route('business.home'));
        $this->assertSame(BusinessMember::ACTIVE, $invite->fresh()->status);
    }

    public function test_roles_managers_invite_travellers_only_and_the_last_admin_stays(): void
    {
        [$account, $admin] = $this->businessAccount();
        $manager = $this->member($account, BusinessMember::MANAGER);
        $finance = $this->member($account, BusinessMember::FINANCE);
        $traveller = $this->member($account);

        $this->actingAs($manager->user)->post(route('business.people.invite'), ['email' => 'boss@example.test', 'role' => 'account_admin'])->assertForbidden();
        $this->actingAs($manager->user)->post(route('business.people.invite'), ['email' => 'new@example.test', 'role' => 'traveller'])->assertSessionHas('success');
        $this->actingAs($manager->user)->patch(route('business.people.update', $finance->id), ['monthly_allowance' => '100'])->assertForbidden();
        $this->actingAs($finance->user)->post(route('business.people.invite'), ['email' => 'x@example.test', 'role' => 'traveller'])->assertForbidden();
        $this->actingAs($traveller->user)->get(route('business.people'))->assertForbidden();

        $this->actingAs($admin->user)->patch(route('business.people.update', $admin->id), ['role' => 'traveller'])->assertSessionHas('error');
        $this->actingAs($admin->user)->delete(route('business.people.destroy', $admin->id))->assertSessionHas('error');
        $this->assertSame(BusinessMember::ADMIN, $admin->fresh()->role);

        $this->actingAs($admin->user)->delete(route('business.people.destroy', $traveller->id))->assertSessionHas('success');
        $this->assertSame(BusinessMember::REMOVED, $traveller->fresh()->status);
        $this->assertSame('removed', PaymentMethod::find($traveller->payment_method_id)->status);

        $this->actingAs($admin->user)->patch(route('business.people.update', $traveller->id + 1000), ['role' => 'traveller'])->assertNotFound();
    }
}
