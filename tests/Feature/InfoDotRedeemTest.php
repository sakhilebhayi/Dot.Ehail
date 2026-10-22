<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'ehail',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'uuid' => null, 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
            'current_team_uuid' => null,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame($user->ownedTeams->first()->id, $user->current_team_id);
    }

    public function test_a_provisioned_user_cannot_sign_in_with_a_password(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertGreaterThan(40, strlen($user->password));
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot());

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'ehail';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);
        $this->post(route('ecosystem.auth'), ['token' => 'bad'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::fake([self::URL => Http::response(['error' => 'unauthorized'], 401)]);
        $this->post(route('ecosystem.auth'), ['token' => 'bad'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_truthy_but_not_true_verified_flag_is_refused(): void
    {
        foreach (['yes', 1, 'true', null] as $flag) {
            Http::fake([self::URL => Http::response($this->identity(['email_verified' => $flag]))]);

            $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_identity_cannot_take_over_an_existing_account(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($existing->password, $existing->fresh()->password);
    }

    public function test_an_identity_without_a_valid_email_is_refused(): void
    {
        foreach (['', 'not-an-email'] as $email) {
            Http::fake([self::URL => Http::response($this->identity(['email' => $email]))]);

            $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => 'unknown'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Inserted directly: this app's token table may not have the expires_at column
        // that createToken() always writes.
        $plain = bin2hex(random_bytes(20));
        $record = PersonalAccessToken::forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'handoff',
            'token' => hash('sha256', $plain),
            'abilities' => ['ecosystem:read'],
        ]);
        $token = $record->id.'|'.$plain;
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        // The token is spent, so the second attempt falls through to InfoDot (faked: refuses).
        Http::fake([self::URL => Http::response([], 403)]);
        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->post(route('ecosystem.auth'), [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ''], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ['x']], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
    }

    public function test_the_session_id_is_regenerated_on_a_redeemed_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->startSession();
        $before = session()->getId();

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_a_safe_in_app_path_is_honoured(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => '/profile/settings?tab=1'], $this->fromInfodot())
            ->assertRedirect(url('/profile/settings?tab=1'));
    }

    public function test_open_redirect_attempts_fall_back_to_the_dashboard(): void
    {
        // Many attempts in one test; the throttle has its own test below.
        $this->withoutMiddleware(ThrottleRequests::class);

        $attempts = [
            'https://evil.test/',
            '//evil.test/',
            '///evil.test',
            '/\\evil.test',
            '\\\\evil.test',
            'evil.test/path',
            'javascript:alert(1)',
            '/../../etc/passwd',
            '/a/%2e%2e/b',
            '/ok%5c%5cevil.test',
            "/ok\r\nLocation: https://evil.test",
            '',
        ];

        foreach ($attempts as $attempt) {
            Http::fake([self::URL => Http::response($this->identity())]);
            auth()->logout();

            $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => $attempt], $this->fromInfodot())
                ->assertRedirect(route('dashboard'));
        }

        // A non-string redirect is ignored rather than causing an error.
        Http::fake([self::URL => Http::response($this->identity())]);
        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => ['/x']], $this->fromInfodot())->assertRedirect(route('dashboard'));
    }

    public function test_the_endpoint_is_throttled(): void
    {
        Http::fake([self::URL => Http::response([], 403)]);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('ecosystem.auth'), ['token' => 'bogus'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->post(route('ecosystem.auth'), ['token' => 'bogus'], $this->fromInfodot())->assertStatus(429);
    }
}
