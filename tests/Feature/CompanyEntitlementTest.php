<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.Ehail honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on; and a trip in progress, the safety flow,
 * dispatch, location pings and payment settlement are never refused. Response bodies follow InfoDot's own tests
 * (CompanyPlatformsEndpointTest, the redeem controller).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'ehail', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'ehail',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'ehail', 'name' => 'Dot.Ehail', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team} a person and their InfoDot company, which is their current team */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        // The header's platform switcher (shared dot-ui) reads the same InfoDot endpoint under its own cache key. These
        // tests count and sequence the ENTITLEMENT check's requests, so the switcher's answer is parked as a recent
        // failure: it then asks nothing and the counts below stay the entitlement check's own. The switcher has its own
        // tests (DotUiHeaderTest).
        Cache::put('infodot:platforms:ehail:'.strtolower($uuid), ['ok' => false, 'personal' => false, 'rows' => []], 3600);

        return [$owner->fresh(), $team];
    }

    private function refusedCompany(string $status = 'suspended'): User
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        return $owner;
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'ehail',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    private function ride(User $passenger, ?User $driver, string $status): Ride
    {
        return Ride::create([
            'passenger_id' => $passenger->id, 'driver_id' => $driver?->id,
            'pickup_address' => '1 Adderley Street', 'dropoff_address' => '2 Strand Street', 'status' => $status,
        ]);
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        [, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first(), 'a company that does not hold the platform is not created');
        $this->assertSame('Globex (old name)', $globex->fresh()->name, 'nor renamed');
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role, 'nor is the role changed');
        $this->assertTrue($ann->belongsToTeam($globex), 'membership is left as it was');
        $this->assertSame($acme->id, $ann->current_team_id, 'and it cannot be the current company');
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam('44444444-4444-4444-8444-444444444444', "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Ehail needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/ehail"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action='));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created for nothing');
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.$this->credential)
            && $request->hasHeader('X-Dot-Platform', 'ehail')
            && $request['platform'] === 'ehail'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms'));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['not_activated', 'Dot.Ehail is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Ehail is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Ehail needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Ehail has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        $owner = $this->refusedCompany($status);
        $this->ride($owner, null, 'completed')->forceFill(['pickup_address' => '77 Secret Close'])->save();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(403)->assertSee($title)
            ->assertSee('href="https://infodot.test/platforms/ehail"', false)
            ->assertDontSee('77 Secret Close')
            ->assertDontSee('Whoops');
        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action='));
        $this->assertSame(1, Ride::count(), 'the data is untouched');
    }

    public function test_a_refused_company_cannot_book_or_open_the_wallet(): void
    {
        $owner = $this->refusedCompany();
        $this->actingAs($owner);

        $this->get(route('rides.create'))->assertForbidden();
        $this->get(route('money.wallet'))->assertForbidden();
        $this->post(route('ehail.dispatch.rides.store'), [])->assertForbidden();
        $this->assertSame(0, Ride::count());
    }

    public function test_a_script_that_asks_for_json_gets_a_json_refusal(): void
    {
        $owner = $this->refusedCompany();

        $response = $this->actingAs($owner)->postJson(route('ehail.dispatch.rides.store'), []);

        $response->assertStatus(403)->assertJsonPath('error', 'entitlement_required')->assertJsonMissingPath('components');
    }

    public static function failures(): array
    {
        return [
            'server error' => [fn () => Http::response('boom', 500)],
            'bad secret' => [fn () => Http::response(['error' => 'x'], 401)],
            'platform switched off' => [fn () => Http::response(['error' => 'x'], 403)],
            'unknown company' => [fn () => Http::response(['error' => 'x'], 404)],
            'not json' => [fn () => Http::response('<html>nope</html>', 200)],
            'malformed body' => [fn () => Http::response(['platforms' => 'nope'])],
            'own row missing' => [fn () => Http::response(['platform' => 'ehail', 'platforms' => [['key' => 'finance', 'entitled' => true]]])],
            'entitled not a boolean' => [fn () => Http::response(['platform' => 'ehail', 'platforms' => [['key' => 'ehail', 'status' => 'suspended', 'entitled' => 'no']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'ehail', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('rides.create'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('rides.create'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        $owner = $this->refusedCompany();
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('EHAIL_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    // ------------------------------------------------------------------ personal workspaces

    /** @return array{0: User, 1: Team} signed in through the handoff with the personal workspace marked as given */
    private function signInPersonally(?bool $personalEntitled): array
    {
        $personal = $this->redeemTeam('55555555-5555-4555-8555-555555555555', "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true);
        $teams = [$personal, $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)];
        $this->signIn('ann@acme.test', $teams, null)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $own = $ann->personalTeam() ?? Team::forceCreate(['user_id' => $ann->id, 'name' => "Ann's Team", 'personal_team' => true]);
        $ann->forceFill(['current_team_id' => $own->id])->save();
        $this->infodotSays([]);

        return [$ann->fresh(), $own];
    }

    public function test_a_personal_workspace_infodot_marked_not_entitled_is_refused_without_asking_the_read_endpoint(): void
    {
        [$ann, $own] = $this->signInPersonally(false);
        $data = $this->ride($ann, null, 'completed');

        $this->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Dot.Ehail needs a company that has switched it on')
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('1 Adderley Street');

        $this->assertNotNull($data->fresh(), 'nothing is deleted');
        $this->assertTrue($own->fresh()->exists);
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_offers_the_switch_to_an_entitled_company(): void
    {
        [$ann] = $this->signInPersonally(false);
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Acme Ltd')
            ->assertSee('name="team_id" value="'.$acme->id.'"', false);
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        [$ann] = $this->signInPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local only', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();

        $this->get(route('dashboard'))->assertStatus(403)->assertSee('needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        $this->signInPersonally(true);

        $this->get(route('dashboard'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        $this->signInPersonally(null);

        $this->assertNull(session(EnsureCompanyEntitled::PERSONAL));
        $this->get(route('dashboard'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME);

        $this->assertNull(session(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('dashboard'))->assertOk();
    }

    // ------------------------------------------------------------------ safety: what is never refused

    public function test_a_trip_in_progress_is_never_interrupted_for_a_rider_or_a_driver(): void
    {
        $owner = $this->refusedCompany();
        $other = User::factory()->create();
        $this->ride($owner, $other, 'in_progress');

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('rides.create'))->assertOk();

        // The same for the driver of a trip under way, whose own company is refused.
        [$driver] = $this->company(self::GLOBEX, 'Globex');
        $this->infodotSays([self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, false, 'suspended'))]);
        $this->ride($other, $driver, 'driver_arriving');
        $this->actingAs($driver)->get(route('dashboard'))->assertOk();

        // A finished trip does not protect anyone.
        Ride::query()->update(['status' => 'completed']);
        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);
        $this->actingAs($driver)->get(route('dashboard'))->assertStatus(403);
    }

    public function test_a_personal_workspace_with_a_trip_in_progress_is_not_interrupted(): void
    {
        [$ann] = $this->signInPersonally(false);
        $this->ride($ann, User::factory()->create(), 'in_progress');

        $this->get(route('dashboard'))->assertOk();
    }

    public static function neverRefused(): array
    {
        return [
            'SOS' => ['post', 'safety.rides.sos', ['ride' => 1]],
            'incident location' => ['post', 'safety.incidents.location', ['incident' => 1]],
            'check-in answer' => ['post', 'safety.check-ins.respond', ['checkIn' => 1]],
            'trip share' => ['post', 'safety.rides.shares.store', ['ride' => 1]],
            'driver heartbeat' => ['post', 'ehail.dispatch.driver.heartbeat', []],
            'driver poll' => ['post', 'ehail.dispatch.driver.sync', []],
            'driver feed' => ['get', 'ehail.dispatch.driver.feed', []],
            'driver offline' => ['post', 'ehail.dispatch.driver.offline', []],
            'driver trip stage' => ['post', 'ehail.dispatch.driver.rides.stage', ['ride' => 1, 'stage' => 'start']],
            'offer accept' => ['post', 'ehail.dispatch.offers.accept', ['offer' => 1]],
            'rider live feed' => ['get', 'ehail.dispatch.rides.feed', ['ride' => 1]],
            'rider cancel' => ['post', 'ehail.dispatch.rides.cancel', ['ride' => 1]],
            'driver location ping' => ['post', 'geo.driver.location', []],
            'live tracking' => ['get', 'geo.rides.driver-location', ['ride' => 1]],
            'pay now' => ['post', 'money.rides.pay-now', ['ride' => 1]],
            'card return' => ['get', 'money.cards.callback', []],
            'cash received' => ['post', 'money.driver.cash-received', ['ride' => 1]],
            'payment return' => ['get', 'rides.pay.callback', ['ride' => 1]],
            'data export' => ['get', 'account.data-export', []],
        ];
    }

    #[DataProvider('neverRefused')]
    public function test_the_safety_dispatch_location_and_payment_routes_are_never_checked(string $method, string $route, array $parameters): void
    {
        $owner = $this->refusedCompany();

        $response = $this->actingAs($owner)->{$method}(route($route, $parameters));

        Http::assertNothingSent();
        $this->assertStringNotContainsString('entitlement_required', (string) $response->getContent());
        $this->assertStringNotContainsString('Dot.Ehail is suspended', (string) $response->getContent());
    }

    public function test_the_operator_console_is_never_refused(): void
    {
        $owner = $this->refusedCompany();
        $owner->forceFill(['is_platform_operator' => true])->save();

        $this->actingAs($owner->fresh())->get(route('ops.home'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_driver_can_always_go_offline_but_not_online(): void
    {
        $owner = $this->refusedCompany();
        $profile = DriverProfile::factory()->create(['user_id' => $owner->id, 'status' => 'approved', 'is_online' => true]);
        $this->actingAs($owner);

        $this->patch(route('drivers.toggle-online', $profile))->assertRedirect();
        $this->assertFalse((bool) $profile->fresh()->is_online, 'going offline worked');
        Http::assertNothingSent();

        $this->patch(route('drivers.toggle-online', $profile))->assertForbidden();
        $this->assertFalse((bool) $profile->fresh()->is_online, 'going online is refused and changes nothing');
    }

    // ------------------------------------------------------------------ live updates and exempt routes

    private function bellSnapshot(): string
    {
        $page = $this->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"[^>]*wire:name="notification-bell"|wire:name="notification-bell"[^>]*wire:snapshot="([^"]+)"|wire:snapshot="([^"]+)"/', $page, $m);
        $snapshot = html_entity_decode(collect(array_slice($m, 1))->filter()->first() ?? '', ENT_QUOTES);
        $this->assertNotSame('', $snapshot, 'the notification bell component is on the page');

        return $snapshot;
    }

    private function livewireMarkAllRead(string $snapshot)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'markAllAsRead', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $owner->notify(new RideCompletedNotification($this->ride(User::factory()->create(), $owner, 'completed')));
        $owner->notify(new RideCompletedNotification($this->ride(User::factory()->create(), $owner, 'completed')));
        $snapshot = $this->bellSnapshot();

        // While the company holds the platform the action works.
        $worked = $this->livewireMarkAllRead($snapshot);
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertSame(0, $owner->unreadNotifications()->count());

        // A new notification arrives, the cached answer expires, InfoDot now says no: the same action is refused,
        // returns no component, and changes nothing.
        $owner->notify(new RideCompletedNotification($this->ride(User::factory()->create(), $owner, 'completed')));
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->livewireMarkAllRead($snapshot);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('components', (array) $response->json());
        $this->assertSame(1, $owner->unreadNotifications()->count(), 'the data is unchanged');
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([]);

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/fares')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->get(route('trip-share.show', 'no-such-token'))->assertStatus(404);
        $this->post(route('paystack.webhook'), [])->assertStatus(401);

        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get('/up')->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_another_companys_status_is_never_asked_about(): void
    {
        [$owner] = $this->company();
        $this->company(self::GLOBEX, 'Globex Corporation');
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GLOBEX));
    }
}
