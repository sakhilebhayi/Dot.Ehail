<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Mail\IncidentEscalationMail;
use App\Domain\Safety\Mail\SosAlertMail;
use App\Domain\Safety\Models\IncidentNotification;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\Sms\BulkSmsGateway;
use App\Domain\Safety\Sms\SmsGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    private function confirmedContact(User $owner, string $email = 'sipho@example.com', ?string $phone = null): TrustedContact
    {
        return TrustedContact::create([
            'user_id' => $owner->id, 'name' => 'Sipho', 'email' => $email, 'email_hash' => TrustedContact::emailHash($email),
            'phone' => $phone, 'confirmed_at' => now(),
        ]);
    }

    /** @return array<string, string> recipient label => status */
    private function outcomes(SafetyIncident $incident): array
    {
        return IncidentNotification::query()->where('safety_incident_id', $incident->id)->pluck('status', 'recipient_label')->all();
    }

    public function test_sos_is_idempotent_per_ride_and_person_and_captures_context(): void
    {
        $this->makeOperator();
        [$ride, $passenger] = $this->makeActiveRide();

        $first = $this->actingAs($passenger)->postJson(route('safety.rides.sos', $ride), ['lat' => -26.2041, 'lng' => 28.0473, 'accuracy_m' => 12])
            ->assertCreated()->assertJsonPath('created', true);
        $this->actingAs($passenger)->postJson(route('safety.rides.sos', $ride))->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, SafetyIncident::count());
        $incident = SafetyIncident::firstOrFail();
        $this->assertSame('critical', $incident->severity->value);
        $this->assertSame($ride->driver_id, $incident->subject_user_id);
        $this->assertSame('12 Secret Home Road, Soweto', $incident->context['ride']['pickup_address']);
        $this->assertNotNull($incident->context['vehicle']['registration']);
        $this->assertEqualsWithDelta(-26.2041, $incident->location_lat, 0.0001);
        $this->assertSame(['raised', 'context_captured'], $incident->events->take(2)->pluck('type')->all());

        $first->assertJsonPath('what_we_did.emergency_numbers.0.tel', 'tel:10111')
            ->assertJsonPath('what_we_did.emergency_numbers.1.number', '10177')
            ->assertJsonPath('what_we_did.emergency_numbers.2.number', '112');
        $this->assertStringContainsString('did not call the police', $first->json('what_we_did.not_done.0'));
    }

    public function test_driver_can_raise_their_own_sos_and_strangers_cannot(): void
    {
        [$ride, , $driver] = $this->makeActiveRide();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->postJson(route('safety.rides.sos', $ride))->assertNotFound();
        $this->actingAs($driver)->postJson(route('safety.rides.sos', $ride))->assertCreated();
        $this->assertSame('driver', SafetyIncident::firstOrFail()->reporter_role);
    }

    public function test_what_we_did_reflects_real_outcomes_including_no_contacts_and_no_sms(): void
    {
        $this->makeOperator();
        [$ride, $passenger] = $this->makeActiveRide();

        $payload = $this->actingAs($passenger)->postJson(route('safety.rides.sos', $ride))->json('what_we_did');
        $actions = collect($payload['actions'])->keyBy('what');

        $this->assertSame('sent', $actions['In-app alert to 1 operations team member']['status']);
        $this->assertSame('skipped', $actions['No confirmed trusted contacts']['status']);
        $this->assertSame('You have no confirmed trusted contacts.', $actions['No confirmed trusted contacts']['why']);
    }

    public function test_email_outcomes_are_reported_honestly_sent_not_delivered_and_failed(): void
    {
        $this->makeOperator();

        // 1. A real transport accepted it: "sent".
        config(['mail.default' => 'smtp']);
        Mail::fake();
        [$ride, $passenger] = $this->makeActiveRide();
        $this->confirmedContact($passenger);
        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos)['incident'];
        Mail::assertSent(SosAlertMail::class, fn (SosAlertMail $m) => $m->hasTo('sipho@example.com') && $m->shareUrl !== null);
        $this->assertSame('sent', $this->outcomes($incident)['Email to Sipho (s***@example.com)']);

        // 2. The mailer only logs: "not_delivered", never "sent".
        config(['mail.default' => 'log']);
        [$ride2, $passenger2] = $this->makeActiveRide();
        $this->confirmedContact($passenger2, 'lerato@example.com');
        $incident2 = app(IncidentService::class)->raise($ride2, $passenger2, IncidentKind::Sos)['incident'];
        $this->assertSame('not_delivered', $this->outcomes($incident2)['Email to Sipho (l***@example.com)']);

        // 3. The transport throws: "failed", and the incident still exists with the rest done.
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        [$ride3, $passenger3] = $this->makeActiveRide();
        $this->confirmedContact($passenger3, 'naledi@example.com');
        $incident3 = app(IncidentService::class)->raise($ride3, $passenger3, IncidentKind::Sos)['incident'];
        $this->assertSame('failed', $this->outcomes($incident3)['Email to Sipho (n***@example.com)']);
        $failed = collect(app(IncidentService::class)->whatWeDid($incident3)['actions'])->firstWhere('status', 'failed');
        $this->assertSame('Failed', $failed['status_text']);
    }

    public function test_opted_out_and_unconfirmed_contacts_are_never_alerted(): void
    {
        Mail::fake();
        [$ride, $passenger] = $this->makeActiveRide();
        TrustedContact::create(['user_id' => $passenger->id, 'name' => 'U', 'email' => 'u@example.com', 'email_hash' => TrustedContact::emailHash('u@example.com')]);
        $out = $this->confirmedContact($passenger, 'out@example.com');
        $out->forceFill(['opted_out_at' => now()])->save();

        app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos);

        Mail::assertNotSent(SosAlertMail::class);
    }

    public function test_sms_is_skipped_when_not_configured_and_sent_or_failed_through_bulksms(): void
    {
        Mail::fake();
        [$ride, $passenger] = $this->makeActiveRide();
        $this->confirmedContact($passenger, 'sipho@example.com', '+27821234567');

        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos)['incident'];
        $sms = IncidentNotification::query()->where('safety_incident_id', $incident->id)->where('channel', 'sms')->firstOrFail();
        $this->assertSame('skipped', $sms->status);
        $this->assertSame('sms_not_configured', $sms->error);

        // Configure BulkSMS (faked HTTP) and raise on another ride.
        Http::fake(['api.bulksms.com/*' => Http::sequence()
            ->push([['id' => 'msg-123', 'type' => 'SENT']], 201)
            ->push(['detail' => 'nope'], 500)]);
        $this->app->instance(SmsGateway::class, new BulkSmsGateway(['endpoint' => 'https://api.bulksms.com/v1/messages', 'token_id' => 'id', 'token_secret' => 'secret']));
        [$ride2, $passenger2] = $this->makeActiveRide();
        $this->confirmedContact($passenger2, 'lerato@example.com', '+27831234567');
        $incident2 = app(IncidentService::class)->raise($ride2, $passenger2, IncidentKind::Sos)['incident'];

        $sms2 = IncidentNotification::query()->where('safety_incident_id', $incident2->id)->where('channel', 'sms')->firstOrFail();
        $this->assertSame('sent', $sms2->status, (string) $sms2->error);
        $this->assertSame('msg-123', $sms2->provider_message_id);
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '+27831234567'
            && str_contains($r['body'], 'does not dispatch emergency services')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('id:secret')));

        [$ride3, $passenger3] = $this->makeActiveRide();
        $this->confirmedContact($passenger3, 'n@example.com', '+27841234567');
        $incident3 = app(IncidentService::class)->raise($ride3, $passenger3, IncidentKind::Sos)['incident'];
        $sms3 = IncidentNotification::query()->where('safety_incident_id', $incident3->id)->where('channel', 'sms')->firstOrFail();
        $this->assertSame('failed', $sms3->status);
        $this->assertSame('provider_http_500', $sms3->error);
    }

    public function test_unacknowledged_incidents_escalate_on_timers_and_acknowledgement_stops_them(): void
    {
        Mail::fake();
        $operator = $this->makeOperator();
        $onCall = $this->makeOperator();
        config([
            'mail.default' => 'smtp',
            'ehail_safety.escalation.levels.1.user_ids' => [$onCall->id],
            'ehail_safety.escalation.levels.1.emails' => ['oncall@example.com'],
            'ehail_safety.escalation.levels.2.user_ids' => [],
            'ehail_safety.escalation.levels.2.emails' => [],
        ]);
        [$ride, $passenger] = $this->makeActiveRide();
        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos)['incident'];
        $service = app(IncidentService::class);

        $this->travel(1)->minutes();
        $this->assertSame(0, $service->escalateDue());

        $this->travel(2)->minutes();
        $this->assertSame(1, $service->escalateDue());
        $this->assertSame(0, $service->escalateDue(), 'the same timer never fires twice');
        $incident->refresh();
        $this->assertSame('escalated', $incident->status->value);
        $this->assertSame(1, $incident->escalation_level);
        Mail::assertSent(IncidentEscalationMail::class, fn ($m) => $m->hasTo('oncall@example.com'));
        $this->assertSame('sent', $this->outcomes($incident)['Email to On-call operations lead (o***@example.com)']);

        // Level 2 has nobody configured: recorded as such, not silently skipped.
        $this->travel(3)->minutes();
        $this->artisan('ehail:safety-sweep')->assertSuccessful();
        $this->assertSame(2, $incident->fresh()->escalation_level);
        $this->assertSame('skipped', $this->outcomes($incident)['Safety manager (nobody configured)']);

        $this->actingAs($operator)->postJson(route('safety.ops.incidents.acknowledge', $incident))->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->travel(30)->minutes();
        $this->assertSame(0, $service->escalateDue());
        $this->assertSame('safety_incident_acknowledged', $passenger->fresh()->notifications->firstWhere('data.type', 'safety_incident_acknowledged')?->data['type']);
    }

    public function test_resolving_frees_the_sos_slot_and_transitions_are_guarded(): void
    {
        $operator = $this->makeOperator();
        [$ride, $passenger] = $this->makeActiveRide();
        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos)['incident'];

        $this->actingAs($operator)->postJson(route('safety.ops.incidents.close', $incident))->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.ops.incidents.resolve', $incident), ['resolution_code' => 'other'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.ops.incidents.resolve', $incident), ['resolution_code' => 'user_safe'])->assertOk();
        $this->actingAs($operator)->postJson(route('safety.ops.incidents.close', $incident))->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAs($passenger)->postJson(route('safety.rides.sos', $ride))->assertCreated();
        $this->assertSame(2, SafetyIncident::count());
    }

    public function test_incident_visibility_is_reporter_only_and_ops_routes_are_operator_only(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Sos)['incident'];

        $this->actingAs($driver)->getJson(route('safety.incidents.show', $incident))->assertNotFound();
        $this->actingAs($driver)->getJson(route('safety.ops.incidents.queue'))->assertForbidden();
        $this->actingAs($driver)->getJson(route('safety.ops.incidents.show', $incident))->assertForbidden();
        $this->actingAs($passenger)->getJson(route('safety.incidents.show', $incident))->assertOk()->assertJsonPath('data.reference', $incident->reference);

        $operator = $this->makeOperator();
        $this->actingAs($operator)->getJson(route('safety.ops.incidents.show', $incident))->assertOk()->assertJsonPath('data.context.ride.id', $ride->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'safety_incident.opened', 'actor_user_id' => $operator->id]);
    }

    public function test_safety_card_shows_verified_driver_facts_and_passenger_facts_without_contact_details(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();

        $card = $this->actingAs($passenger)->getJson(route('safety.rides.show', $ride))->assertOk();
        $card->assertJsonPath('driver.verified', true)
            ->assertJsonPath('driver.vehicle.verified', true)
            ->assertJsonPath('driver.rating.band', 'new')
            ->assertJsonPath('passenger', null)
            ->assertJsonPath('emergency_numbers.0.number', '10111');
        $this->assertStringNotContainsString($driver->email, $card->getContent());

        $driverView = $this->actingAs($driver)->getJson(route('safety.rides.show', $ride))->assertOk();
        $driverView->assertJsonPath('passenger.first_name', 'Thandi')
            ->assertJsonPath('passenger.phone_verified', null)
            ->assertJsonPath('driver', null);
        $this->assertStringNotContainsString($passenger->email, $driverView->getContent());
        $this->assertStringNotContainsString('Mokoena', $driverView->getContent());

        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('safety.rides.show', $ride))->assertNotFound();
    }
}
