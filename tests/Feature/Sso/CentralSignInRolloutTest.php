<?php

namespace Tests\Feature\Sso;

use App\Http\Middleware\RequireRecentSignIn;
use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\EhailProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** R-54: Dot.Ehail behind DOTSSO_ENABLED. Off, nothing changes; on, every sign-in entry goes to InfoDot, the public shop stays public. */
class CentralSignInRolloutTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config(['dotsso.provisioner' => EhailProvisioner::class, 'dotsso.enabled' => false]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create();

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/sign-in?return=/fares')->assertRedirect(route('login'));
        $this->get('/fares')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
        $this->get('/sso/callback')->assertNotFound();
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_public_fare_calculator_stays_public_and_its_sign_in_links_return_to_the_page(): void
    {
        $this->on();

        // No redirect to a login: the cart, the product list and the help pages are guest pages.
        $html = $this->get('/fares')->assertOk()->getContent();
        $this->assertStringContainsString(route('sign-in', ['return' => '/fares']), $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);

    }

    public function test_the_landing_page_offers_create_account_only_while_the_central_sign_in_is_on(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Create account');

        $this->on();
        $this->get('/')->assertOk()->assertSee('Create account')->assertSee(route('dotsso.login', ['screen' => 'register']), false);
    }

    public function test_sign_in_from_a_public_page_comes_back_to_that_page(): void
    {
        $this->on();

        $this->get('/sign-in?return=/fares')->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-uuid-1', 'email' => 'shopper@example.test'])->assertRedirect('/fares');
        $this->assertAuthenticated();
    }

    public function test_the_return_value_of_sign_in_is_never_an_open_redirect_or_a_sign_in_door(): void
    {
        $this->on();

        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', '/login', '/sso/login', '/safety/contact/stop/1', '/webhooks/paystack'] as $bad) {
            $this->flushSession();
            $this->get('/sign-in?return='.urlencode($bad))->assertRedirect(route('dotsso.login'));
            $this->assertNull(session('url.intended'), $bad);
        }

        $this->flushSession();
        $this->get('/sign-in?return=/fares')->assertRedirect(route('dotsso.login'));
        $this->assertSame('/fares', session('url.intended'));
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/fares')->assertOk()->assertSee(route('login'), false);
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/rides')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/rides');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_keeps_data_and_(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect(route('dotsso.login'));
        $this->post('/webhooks/paystack', ['event' => 'charge.success'], ['x-paystack-signature' => 'bad'])->assertStatus(401);
    }

    public function test_an_operator_keeps_the_operator_flag_when_linked_at_first_sign_in(): void
    {
        $this->on();
        $op = User::factory()->withPersonalTeam()->create(['email' => 'op@example.test', 'email_verified_at' => now(), 'is_platform_operator' => true]);

        $this->signInVia(['sub' => 'infodot-op-1', 'email' => 'op@example.test']);

        $op->refresh();
        $this->assertSame('infodot-op-1', $op->infodot_user_uuid);
        $this->assertTrue((bool) $op->is_platform_operator);
    }

    public function test_a_new_infodot_person_is_never_made_an_operator(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-new-2', 'email' => 'fresh@example.test']);

        $this->assertFalse((bool) User::where('email', 'fresh@example.test')->firstOrFail()->is_platform_operator);
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => 'infodot-u-3']);

        $this->signInVia(['sub' => 'infodot-u-3', 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_the_error_page_has_one_h1_a_reference_and_a_way_back(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?error=access_denied&state=x')->assertStatus(400)->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Back to Dot.Ehail', $html);
        $this->assertStringContainsString('Reference:', $html);
    }

    public function test_the_team_invitation_mail_points_new_people_at_the_central_registration(): void
    {
        $this->on();
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Seller Co', 'personal_team' => false]);
        $invitation = $team->teamInvitations()->create(['email' => 'x@example.test', 'role' => 'editor']);

        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), (new TeamInvitation($invitation))->render());
    }

    public function test_a_back_channel_logout_ends_a_signed_in_session_on_a_sanctum_guarded_route(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-bc-1', 'email' => 'bc@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-bc-1', 'revoked_at_ms' => (int) floor(microtime(true) * 1000) + 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect();
        $this->assertFalse(session()->has('dotsso.session'), 'the SSO session was invalidated');
    }

    public function test_trip_share_and_trusted_contact_pages_and_the_fare_calculator_are_never_intercepted(): void
    {
        $this->on();

        $this->get('/fares')->assertOk();
        $this->assertFalse($this->get('/t/not-a-real-token')->isRedirect(), 'a trip-share link is a public token page, never a redirect to a login');
        $this->assertContains($this->get('/safety/contact/stop/1')->getStatusCode(), [403, 404]); // signed link required, never a redirect to a login
        $this->assertFalse($this->get('/safety/contact/confirm/not-a-token')->isRedirect());
    }

    public function test_the_recent_sign_in_gate_still_works_after_a_central_sign_in(): void
    {
        $this->on();
        Route::post('/__gate', fn () => response('ok'))->middleware(['web', 'auth', 'ehail.recent-auth']);

        // A central sign-in fires the Login event, which stamps the session: the gate lets the person through at once.
        $this->signInVia(['sub' => 'infodot-gate-1', 'email' => 'gate@example.test'])->assertRedirect('/dashboard');
        $this->assertNotNull(session(RequireRecentSignIn::SIGNED_IN_AT));
        $this->post('/__gate')->assertOk();

        // An old session still hits the gate and is sent to the password confirmation (not intercepted).
        $this->withSession([RequireRecentSignIn::SIGNED_IN_AT => time() - 3600])->post('/__gate')->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertOk()->assertSee('Confirm your password');
    }

    public function test_an_infodot_only_account_can_pass_the_gate_by_signing_in_again(): void
    {
        $this->on();
        Route::get('/__gate', fn () => response('ok'))->middleware(['web', 'auth', 'ehail.recent-auth']);
        $this->signInVia(['sub' => 'infodot-gate-2', 'email' => 'gate2@example.test']);

        $this->withSession([RequireRecentSignIn::SIGNED_IN_AT => time() - 3600])->get('/__gate')->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertSee('Sign in again with InfoDot');

        $this->post(route('sign-in-again'))->assertRedirect(route('dotsso.login'));
        $this->assertGuest();
        $this->assertSame(url('/__gate'), session('url.intended'));

        $this->signInVia(['sub' => 'infodot-gate-2', 'email' => 'gate2@example.test'])->assertRedirect('/__gate');
        $this->get('/__gate')->assertOk();
    }

    public function test_the_sign_in_again_button_is_absent_and_inert_while_the_flag_is_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.confirm'))->assertOk()->assertDontSee('Sign in again with InfoDot');
        $this->actingAs($user)->post(route('sign-in-again'))->assertRedirect(route('password.confirm'));
        $this->assertAuthenticatedAs($user);
    }
}
