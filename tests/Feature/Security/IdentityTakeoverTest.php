<?php

namespace Tests\Feature\Security;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Domain\Business\Models\BusinessMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * R-46 security S-01: an email address a person has not proven must not unlock anything matched by email
 * (business invitations listed in the portal, InfoDot first sign-in).
 */
class IdentityTakeoverTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_changing_the_email_clears_its_verification(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'me@example.test']);

        $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => 'cfo@acme.example']);

        $this->assertSame('cfo@acme.example', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_keeping_the_same_email_keeps_its_verification(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'me@example.test']);

        $this->actingAs($user)->put('/user/profile-information', ['name' => 'New Name', 'email' => 'me@example.test']);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_user_who_takes_an_invited_email_cannot_accept_the_listed_invitation(): void
    {
        [$account, $admin] = $this->businessAccount();
        $this->actingAs($admin->user)->post(route('business.people.invite'), ['email' => 'cfo@acme.example', 'role' => 'account_admin']);
        $invite = BusinessMember::where('email', 'cfo@acme.example')->sole();

        $attacker = User::factory()->withPersonalTeam()->create(['email' => 'mallory@example.test']);
        $this->actingAs($attacker)->put('/user/profile-information', ['name' => 'Mallory', 'email' => 'cfo@acme.example']);
        $attacker->refresh();

        $this->actingAs($attacker)->get(route('business.home'))->assertDontSee($account->displayName());
        $this->actingAs($attacker)->post(route('business.invitations.accept-listed', $invite->id))->assertNotFound();
        $this->assertSame(BusinessMember::INVITED, $invite->fresh()->status);
    }

    public function test_infodot_sign_in_takes_back_an_address_another_account_claimed(): void
    {
        $attacker = User::factory()->withPersonalTeam()->create(['email' => 'mallory@example.test']);
        $this->actingAs($attacker)->put('/user/profile-information', ['name' => 'Mallory', 'email' => 'victim@example.test']);
        $oldHash = $attacker->fresh()->password;

        $user = app(ProvisionUserFromInfoDot::class)(['user' => ['email' => 'victim@example.test', 'email_verified' => true, 'name' => 'Victim']]);

        // The unproven account is adopted with a new password, so the attacker can no longer sign in to it.
        $this->assertSame($attacker->id, $user->id);
        $this->assertNotSame($oldHash, $user->fresh()->password);
    }

    public function test_sign_in_attempts_are_limited_per_address_as_well_as_per_account(): void
    {
        // One address spraying many accounts: the per-address limit stops it (S-32).
        $statuses = [];
        foreach (range(1, 32) as $n) {
            $statuses[] = $this->post('/login', ['email' => "user{$n}@example.test", 'password' => 'wrong'])->status();
        }

        $this->assertContains(429, $statuses);
        $this->assertSame(429, $this->post('/login', ['email' => 'fresh@example.test', 'password' => 'wrong'])->status());
    }

    public function test_one_account_attacked_from_many_addresses_is_limited(): void
    {
        $statuses = [];
        foreach (range(1, 32) as $n) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$n}"])->post('/login', ['email' => 'victim@example.test', 'password' => 'wrong'])->status();
        }

        $this->assertSame(429, end($statuses));
    }
}
