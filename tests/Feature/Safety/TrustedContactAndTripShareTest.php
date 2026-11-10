<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Mail\TripShareMail;
use App\Domain\Safety\Mail\TrustedContactConfirmationMail;
use App\Domain\Safety\Models\TripShare;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\TripShareService;
use App\Http\Middleware\RequireRecentSignIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

class TrustedContactAndTripShareTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Adding a trusted contact needs a recent sign-in (R-46 security S-15).
        $this->withSession([RequireRecentSignIn::SIGNED_IN_AT => time()]);
    }

    /** Adds a contact and returns it with the confirmation token taken from the email. */
    private function addContact(User $owner, string $email = 'sipho@example.com', ?string $phone = null): array
    {
        $this->actingAs($owner)->postJson(route('safety.contacts.store'), ['name' => 'Sipho', 'email' => $email, 'phone' => $phone])->assertCreated();

        $token = null;
        Mail::assertSent(TrustedContactConfirmationMail::class, function (TrustedContactConfirmationMail $mail) use ($email, &$token) {
            if ($mail->hasTo($email)) {
                $token = basename(parse_url($mail->confirmUrl, PHP_URL_PATH));

                return true;
            }

            return false;
        });

        return [TrustedContact::query()->where('user_id', $owner->id)->latest('id')->firstOrFail(), $token];
    }

    public function test_contact_is_stored_encrypted_and_unusable_until_confirmed_by_post(): void
    {
        Mail::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        [$contact, $token] = $this->addContact($owner, 'sipho@example.com', '082 123 4567');

        $raw = DB::table('trusted_contacts')->first();
        $this->assertStringNotContainsString('sipho@example.com', (string) $raw->email);
        $this->assertSame('+27821234567', $contact->phone);
        $this->assertFalse($contact->isUsable());
        $this->assertNotSame($token, $raw->confirm_token_hash);

        // A mail scanner following the link (GET) must not confirm anything.
        $this->get(route('safety.contacts.confirm.show', $token))->assertOk()->assertSee('Yes, I agree');
        $this->assertNull($contact->fresh()->confirmed_at);

        $this->post(route('safety.contacts.confirm', $token))->assertOk()->assertSee('You are a trusted contact');
        $this->assertTrue($contact->fresh()->isUsable());
    }

    public function test_confirmation_link_expires_and_wrong_tokens_do_nothing(): void
    {
        Mail::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        [$contact, $token] = $this->addContact($owner);

        $this->post(route('safety.contacts.confirm', str_repeat('x', 64)))->assertOk()->assertSee('not valid');

        $this->travel(73)->hours();
        $this->post(route('safety.contacts.confirm', $token))->assertSee('not valid');
        $this->assertNull($contact->fresh()->confirmed_at);
    }

    public function test_contact_can_decline_and_then_stop_alerts_through_signed_link(): void
    {
        Mail::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        [$contact, $token] = $this->addContact($owner);

        $this->post(route('safety.contacts.opt-out', $token))->assertSee('You will not be contacted');
        $this->assertNotNull($contact->fresh()->opted_out_at);
        // Declined contacts cannot then be confirmed with the same link.
        $this->post(route('safety.contacts.confirm', $token))->assertSee('not valid');

        $other = TrustedContact::create(['user_id' => $owner->id, 'name' => 'Lerato', 'email' => 'l@example.com', 'email_hash' => TrustedContact::emailHash('l@example.com'), 'confirmed_at' => now()]);
        $this->get(route('safety.contacts.unsubscribe', ['contact' => $other->id]))->assertForbidden();
        $signed = URL::signedRoute('safety.contacts.unsubscribe', ['contact' => $other->id]);
        $this->get($signed)->assertOk();
        $this->assertNull($other->fresh()->opted_out_at);
        $this->post($signed)->assertOk();
        $this->assertNotNull($other->fresh()->opted_out_at);
    }

    public function test_limit_of_five_no_duplicates_not_yourself_and_owner_isolation(): void
    {
        Mail::fake();
        $owner = User::factory()->withPersonalTeam()->create();

        foreach (range(1, 5) as $i) {
            $this->actingAs($owner)->postJson(route('safety.contacts.store'), ['name' => "C{$i}", 'email' => "c{$i}@example.com"])->assertCreated();
        }

        $this->actingAs($owner)->postJson(route('safety.contacts.store'), ['name' => 'Six', 'email' => 'c6@example.com'])->assertUnprocessable();
        TrustedContact::query()->where('user_id', $owner->id)->first()->delete();
        $this->actingAs($owner)->postJson(route('safety.contacts.store'), ['name' => 'Dup', 'email' => 'C2@Example.com'])->assertUnprocessable();
        $this->actingAs($owner)->postJson(route('safety.contacts.store'), ['name' => 'Me', 'email' => $owner->email])->assertUnprocessable();

        $intruder = User::factory()->withPersonalTeam()->create();
        $contact = TrustedContact::query()->where('user_id', $owner->id)->first();
        $this->actingAs($intruder)->deleteJson(route('safety.contacts.destroy', $contact))->assertNotFound();
        $this->actingAs($intruder)->getJson(route('safety.contacts.index'))->assertJsonCount(0, 'data');
    }

    public function test_share_token_is_unguessable_hashed_at_rest_and_page_sets_no_cookies(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();

        $response = $this->actingAs($passenger)->postJson(route('safety.rides.shares.store', $ride))->assertCreated();
        $url = $response->json('url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertFalse(DB::table('trip_shares')->where('token_hash', $token)->exists());
        $this->assertTrue(DB::table('trip_shares')->where('token_hash', hash('sha256', $token))->exists());

        $second = basename(parse_url($this->actingAs($passenger)->postJson(route('safety.rides.shares.store', $ride))->json('url'), PHP_URL_PATH));
        $this->assertNotSame($token, $second);

        // A fresh visitor: no session, no cookies issued, not indexable, not cached.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $page = $this->get('/t/'.$token)->assertOk();
        $this->assertSame([], $page->headers->getCookies());
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $page->assertSee('name="robots" content="noindex', false);
    }

    public function test_public_payload_leaks_no_personal_identifiers(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $token = basename(parse_url($this->actingAs($passenger)->postJson(route('safety.rides.shares.store', $ride))->json('url'), PHP_URL_PATH));
        $this->app['auth']->forgetGuards();

        $json = $this->getJson('/t/'.$token.'/status')->assertOk();
        $html = $this->get('/t/'.$token)->assertOk()->getContent();

        foreach ([$json->getContent(), $html] as $body) {
            $this->assertStringNotContainsString('Mokoena', $body);
            $this->assertStringNotContainsString('Secret Home Road', $body);
            $this->assertStringNotContainsString('Private Office Park', $body);
            $this->assertStringNotContainsString($passenger->email, $body);
            $this->assertStringNotContainsString($driver->email, $body);
            $this->assertStringNotContainsString('"passenger_id"', $body);
        }

        $json->assertJsonPath('passenger_first_name', 'Thandi')
            ->assertJsonPath('state_label', 'Trip in progress')
            ->assertJsonPath('approximate_position', null)
            ->assertJsonPath('eta_seconds', null);
        $this->assertNotNull($json->json('vehicle.registration'));
    }

    public function test_link_is_dead_after_revocation_and_thirty_minutes_after_the_trip(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $service = app(TripShareService::class);

        $revoked = $service->create($ride, $passenger);
        $live = $service->create($ride, $passenger);

        $this->actingAs($passenger)->deleteJson(route('safety.shares.revoke', $revoked['share']))->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->get('/t/'.$revoked['token'])->assertNotFound();
        $this->get('/t/'.$live['token'])->assertOk();

        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->travel(29)->minutes();
        $this->get('/t/'.$live['token'])->assertOk()->assertSee('Trip completed');
        $this->travel(2)->minutes();
        $this->get('/t/'.$live['token'])->assertNotFound();
        $this->getJson('/t/'.$live['token'].'/status')->assertNotFound()->assertJsonPath('state', 'unavailable');
        $this->get('/t/not-a-token')->assertNotFound();
    }

    public function test_hard_expiry_applies_to_rides_that_never_end(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $share = app(TripShareService::class)->create($ride, $passenger);

        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->get('/t/'.$share['token'])->assertNotFound();
    }

    public function test_only_ride_parties_can_share_and_only_creators_can_revoke(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->postJson(route('safety.rides.shares.store', $ride))->assertNotFound();
        $share = app(TripShareService::class)->create($ride, $passenger)['share'];
        $this->actingAs($stranger)->deleteJson(route('safety.shares.revoke', $share))->assertNotFound();
        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_sharing_to_a_confirmed_contact_emails_the_link_and_reports_honestly(): void
    {
        Mail::fake();
        [$ride, $passenger] = $this->makeActiveRide();
        $unconfirmed = TrustedContact::create(['user_id' => $passenger->id, 'name' => 'U', 'email' => 'u@example.com', 'email_hash' => TrustedContact::emailHash('u@example.com')]);
        $confirmed = TrustedContact::create(['user_id' => $passenger->id, 'name' => 'Sipho', 'email' => 's@example.com', 'email_hash' => TrustedContact::emailHash('s@example.com'), 'confirmed_at' => now()]);

        $this->actingAs($passenger)->postJson(route('safety.rides.shares.store', $ride), ['trusted_contact_id' => $unconfirmed->id])->assertUnprocessable();

        // The test mailer is "array": accepted but not delivered, and the response says so.
        $this->actingAs($passenger)->postJson(route('safety.rides.shares.store', $ride), ['trusted_contact_id' => $confirmed->id])
            ->assertCreated()->assertJsonPath('delivery.status', 'not_delivered');
        Mail::assertSent(TripShareMail::class, fn (TripShareMail $m) => $m->hasTo('s@example.com'));
        $this->assertSame(1, TripShare::query()->where('trusted_contact_id', $confirmed->id)->count());
    }
}
