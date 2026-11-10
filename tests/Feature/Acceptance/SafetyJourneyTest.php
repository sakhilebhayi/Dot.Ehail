<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Safety\Mail\IncidentEscalationMail;
use App\Domain\Safety\Mail\SosAlertMail;
use App\Domain\Safety\Mail\TrustedContactConfirmationMail;
use App\Domain\Safety\Models\IncidentNotification;
use App\Domain\Safety\Models\SafetyIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * (S) Safety end to end: an SOS during a trip, with the context the other domains provide, the alerts that really
 * went out, escalation on the timer, and resolution (R46_INTEGRATION.md section 5).
 */
class SafetyJourneyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_sos_during_a_trip_alerts_contacts_and_operations_escalates_and_is_resolved(): void
    {
        $operator = $this->operator();
        $onCall = $this->makeOperator();
        config(['ehail_safety.escalation.levels.1.user_ids' => [$onCall->id], 'ehail_safety.escalation.levels.1.emails' => ['oncall@example.test']]);

        // The passenger names a trusted contact, who confirms from the emailed link.
        $passenger = $this->registerUser('Ayanda Zulu', 'ayanda@example.test');
        $this->savedCard($passenger);
        $this->actingAs($passenger)->postJson(route('safety.contacts.store'), ['name' => 'Bongani', 'email' => 'bongani@example.test'])->assertCreated();
        $token = null;
        Mail::assertSent(TrustedContactConfirmationMail::class, function (TrustedContactConfirmationMail $mail) use (&$token) {
            $token = basename(parse_url($mail->confirmUrl, PHP_URL_PATH));

            return $mail->hasTo('bongani@example.test');
        });
        $this->post(route('safety.contacts.confirm', $token))->assertOk();

        // A trip is under way and the phone has posted part of the route.
        $driver = $this->verifiedDriver('Mandla Khoza');
        $this->goOnlineAt($driver);
        $rideId = $this->tripUnderWay($passenger, $driver);
        $this->drive($driver, self::PICKUP, [self::PICKUP[0] + 0.0135, self::PICKUP[1]]);

        // SOS.
        $sos = $this->actingAs($passenger)->postJson(route('safety.rides.sos', $rideId), ['lat' => -33.9265, 'lng' => 18.4101, 'accuracy_m' => 15])
            ->assertCreated()->assertJsonPath('created', true)->json('what_we_did');
        $incident = SafetyIncident::sole();
        $this->assertSame($rideId, $incident->ride_id);
        $this->assertSame('critical', $incident->severity->value);
        $this->assertSame('in_progress', $incident->context['ride']['status']);
        $this->assertSame('Toyota', explode(' ', $incident->context['vehicle']['description'])[1]);
        $this->assertEqualsWithDelta(-33.9265, $incident->context['driver_position']['lat'], 0.0002, 'the driver\'s last position from geo');
        $this->assertGreaterThan(5, count($incident->context['trail']), 'the recorded trail from geo');

        // The trusted contact was emailed with a live trip-share link; operations got an in-app alert.
        Mail::assertSent(SosAlertMail::class, fn (SosAlertMail $m) => $m->hasTo('bongani@example.test') && $m->shareUrl !== null);
        $this->assertTrue($operator->fresh()->notifications()->exists(), 'operations alerted in the app');

        // "What we did" lists exactly what happened, from the notification records.
        $actions = collect($sos['actions'])->keyBy('what');
        $this->assertSame(IncidentNotification::SENT, $actions->first(fn ($a) => str_starts_with($a['what'], 'Email to Bongani'))['status']);
        $this->assertSame(IncidentNotification::SENT, $actions->first(fn ($a) => str_starts_with($a['what'], 'In-app alert to'))['status']);
        $this->assertNull($actions->first(fn ($a) => ($a['channel'] ?? null) === 'sms' && $a['status'] === IncidentNotification::SENT), 'no SMS provider: never claimed as sent');
        $this->assertStringContainsString('did not call the police', $sos['not_done'][0]);
        $this->assertSame('tel:10111', $sos['emergency_numbers'][0]['tel']);

        // The trip's raw trail is held for evidence while the incident is open.
        $this->assertSame([$rideId], app(TrailRetentionHold::class)->held([$rideId]));

        // Nobody acknowledged within the critical timer: the sweep escalates to the on-call lead.
        $this->travel(3)->minutes();
        $this->artisan('ehail:safety-sweep')->assertSuccessful();
        $this->assertSame(1, $incident->fresh()->escalation_level);
        Mail::assertSent(IncidentEscalationMail::class, fn ($m) => $m->hasTo('oncall@example.test'));

        // Operations acknowledge, then resolve with a code; the timeline records each step.
        $this->actingAs($operator)->postJson(route('safety.ops.incidents.acknowledge', $incident))->assertOk();
        $this->actingAs($operator)->postJson(route('safety.ops.incidents.resolve', $incident), ['resolution_code' => 'user_safe', 'note' => 'Called the passenger; safe at destination.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $view = $this->actingAs($operator)->getJson(route('safety.ops.incidents.show', $incident))->assertOk()->json();
        $this->assertSame(['raised', 'context_captured'], array_slice(array_column($view['timeline'], 'type'), 0, 2));
        $this->assertContains('escalated', array_column($view['timeline'], 'type'));
        $this->assertContains('resolved', array_column($view['timeline'], 'type'));
        $this->assertSame([], app(TrailRetentionHold::class)->held([$rideId]), 'no hold once resolved');
    }
}
