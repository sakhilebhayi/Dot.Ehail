<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

class SupportCaseTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('safety-files');
    }

    public function test_lost_property_relays_masked_messages_between_passenger_and_driver(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $case = $this->actingAs($passenger)->postJson(route('support.cases.store'), [
            'category' => 'lost_property',
            'subject' => 'Black backpack',
            'body' => 'I left my backpack. Call me on 082 555 1234 or mail thandi@example.com',
            'ride_id' => $ride->id,
        ])->assertCreated()->assertJsonPath('data.priority', 'high')->json('data');

        $message = SupportMessage::firstOrFail();
        $this->assertStringNotContainsString('082 555 1234', $message->body);
        $this->assertStringNotContainsString('thandi@example.com', $message->body);
        $this->assertStringContainsString('[number hidden]', $message->body);

        // The driver is the counterparty: told, can read the participants thread, sees no names.
        $this->assertCount(1, $this->notificationsOfType($driver, 'support_relay'));
        $view = $this->actingAs($driver)->getJson(route('support.cases.show', $case['id']))->assertOk()
            ->assertJsonPath('data.my_role', 'counterparty')
            ->assertJsonPath('data.subject', 'Item left after a trip')
            ->assertJsonPath('messages.0.from', 'The passenger');
        $this->assertStringNotContainsString('Thandi', $view->getContent());

        $this->actingAs($driver)->postJson(route('support.cases.reply', $case['id']), ['body' => 'Found it. WhatsApp +27 83 111 2222'])->assertCreated();
        $this->assertStringNotContainsString('111 2222', SupportMessage::latest('id')->first()->body);

        $this->actingAs($passenger)->getJson(route('support.cases.show', $case['id']))->assertJsonPath('messages.1.from', 'The driver');
    }

    public function test_internal_notes_are_hidden_and_the_counterparty_never_sees_requester_only_messages(): void
    {
        $operator = $this->makeOperator();
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $case = SupportCase::find($this->actingAs($passenger)->postJson(route('support.cases.store'), [
            'category' => 'lost_property', 'subject' => 'Phone', 'body' => 'My phone', 'ride_id' => $ride->id,
        ])->json('data.id'));

        $this->actingAs($operator)->postJson(route('support.ops.cases.reply', $case), ['body' => 'Internal: driver has a history', 'visibility' => 'internal'])->assertCreated();
        $this->actingAs($operator)->postJson(route('support.ops.cases.reply', $case), ['body' => 'Only for the passenger', 'visibility' => 'requester'])->assertCreated();

        $passengerView = $this->actingAs($passenger)->getJson(route('support.cases.show', $case))->getContent();
        $driverView = $this->actingAs($driver)->getJson(route('support.cases.show', $case))->getContent();

        $this->assertStringNotContainsString('Internal: driver has a history', $passengerView);
        $this->assertStringContainsString('Only for the passenger', $passengerView);
        $this->assertStringNotContainsString('Only for the passenger', $driverView);
        $this->assertStringNotContainsString('Internal', $driverView);
        $this->assertSame('in_progress', $case->fresh()->status->value);
        $this->assertNotNull($case->fresh()->first_responded_at);
    }

    public function test_status_transitions_are_guarded_and_resolution_needs_a_code(): void
    {
        $operator = $this->makeOperator();
        $user = User::factory()->withPersonalTeam()->create();
        $case = SupportCase::find($this->actingAs($user)->postJson(route('support.cases.store'), [
            'category' => 'account_problem', 'subject' => 'Cannot change email', 'body' => 'Help',
        ])->json('data.id'));

        $this->actingAs($user)->postJson(route('support.ops.cases.status', $case), ['status' => 'resolved', 'resolution_code' => 'answered'])->assertForbidden();
        $this->actingAs($operator)->postJson(route('support.ops.cases.status', $case), ['status' => 'waiting_on_user'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('support.ops.cases.status', $case), ['status' => 'triaged'])->assertOk();
        $this->actingAs($operator)->postJson(route('support.ops.cases.reply', $case), ['body' => 'Which email?', 'await_user' => true])->assertCreated();
        $this->assertSame('waiting_on_user', $case->fresh()->status->value);

        // The user's reply puts it back in progress.
        $this->actingAs($user)->postJson(route('support.cases.reply', $case), ['body' => 'The old one'])->assertCreated();
        $this->assertSame('in_progress', $case->fresh()->status->value);

        $this->actingAs($operator)->postJson(route('support.ops.cases.status', $case), ['status' => 'resolved'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('support.ops.cases.status', $case), ['status' => 'resolved', 'resolution_code' => 'answered'])->assertOk();
        $this->actingAs($operator)->postJson(route('support.ops.cases.status', $case), ['status' => 'closed'])->assertOk();
        $this->actingAs($user)->postJson(route('support.cases.reply', $case), ['body' => 'One more thing'])->assertUnprocessable();
    }

    public function test_sla_breach_escalates_once_and_raises_priority(): void
    {
        $operator = $this->makeOperator();
        $user = User::factory()->withPersonalTeam()->create();
        $case = SupportCase::find($this->actingAs($user)->postJson(route('support.cases.store'), [
            'category' => 'account_problem', 'subject' => 'Locked out', 'body' => 'Help',
        ])->json('data.id'));
        $this->assertSame('normal', $case->priority->value);

        $this->travel(7)->hours();
        $this->artisan('ehail:support-sla')->assertSuccessful();
        $this->assertNull($case->fresh()->sla_breached_at);

        $this->travel(2)->hours();
        $this->artisan('ehail:support-sla')->assertSuccessful();
        $this->artisan('ehail:support-sla')->assertSuccessful();
        $case->refresh();
        $this->assertNotNull($case->sla_breached_at);
        $this->assertSame(1, $case->escalation_level);
        $this->assertSame('high', $case->priority->value);
        $this->assertCount(1, $this->notificationsOfType($operator, 'support_escalated'));
    }

    public function test_fare_dispute_goes_to_the_operators_fare_review_through_the_pricing_seam(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // Since integration the seam is pricing's fare review (D-700): submitted to operators, never an automatic change.
        $id = $this->actingAs($passenger)->postJson(route('support.cases.store'), [
            'category' => 'fare_dispute', 'subject' => 'Charged twice the estimate', 'body' => 'The estimate was R80', 'ride_id' => $ride->id, 'requested_amount_minor' => 8000,
        ])->assertCreated()->assertJsonPath('data.fare_review', 'submitted')->json('data.id');

        $case = SupportCase::find($id);
        $this->assertSame(8000, $case->meta['fare_review']['requested_amount_minor']);
        $this->assertNull($case->meta['fare_review']['fare'], 'this ride predates pricing: no fare record is invented');
        $this->actingAs($passenger)->getJson(route('support.cases.show', $id))->assertSee('Your dispute is with an operator.');
    }

    public function test_cases_need_own_rides_and_conduct_cases_follow_roles(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->postJson(route('support.cases.store'), ['category' => 'post_trip', 'subject' => 'x', 'body' => 'y', 'ride_id' => $ride->id])
            ->assertUnprocessable()->assertJsonValidationErrors('ride_id');
        $this->actingAs($passenger)->postJson(route('support.cases.store'), ['category' => 'fare_dispute', 'subject' => 'x', 'body' => 'y'])
            ->assertUnprocessable()->assertJsonValidationErrors('ride_id');
        $this->actingAs($driver)->postJson(route('support.cases.store'), ['category' => 'driver_conduct', 'subject' => 'x', 'body' => 'y', 'ride_id' => $ride->id])
            ->assertUnprocessable();
        $this->actingAs($driver)->postJson(route('support.cases.store'), ['category' => 'passenger_conduct', 'subject' => 'x', 'body' => 'y', 'ride_id' => $ride->id])
            ->assertCreated()->assertJsonPath('data.priority', 'high');

        $case = SupportCase::firstOrFail();
        $this->actingAs($stranger)->getJson(route('support.cases.show', $case))->assertNotFound();
        $this->actingAs($passenger)->getJson(route('support.cases.show', $case))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('support.cases.index'))->assertJsonCount(0, 'data');
    }

    public function test_safety_category_raises_an_incident_for_the_safety_team(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();

        $id = $this->actingAs($passenger)->postJson(route('support.cases.store'), [
            'category' => 'safety_incident', 'subject' => 'Driver was speeding', 'body' => '140 on the M1', 'ride_id' => $ride->id,
        ])->assertCreated()->assertJsonPath('data.priority', 'urgent')->json('data.id');

        $incident = SafetyIncident::firstOrFail();
        $this->assertSame('support_case', $incident->source);
        $this->assertSame($incident->id, SupportCase::find($id)->safety_incident_id);
    }

    public function test_attachments_are_reencoded_and_only_visible_to_people_who_can_see_the_message(): void
    {
        $operator = $this->makeOperator();
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        $id = $this->actingAs($user)->postJson(route('support.cases.store'), [
            'category' => 'account_problem', 'subject' => 'Screenshot', 'body' => 'See attached', 'attachment' => $this->jpegUpload('screen.jpg'),
        ])->assertCreated()->json('data.id');

        $message = SupportMessage::query()->whereNotNull('attachment_path')->firstOrFail();
        $this->assertStringNotContainsString('GPS-SECRET-MARKER-123', Storage::disk('safety-files')->get($message->attachment_path));

        $this->actingAs($other)->get(route('support.attachments.show', $message))->assertNotFound();
        $this->actingAs($user)->get(route('support.attachments.show', $message))->assertOk();
        $this->actingAs($operator)->get(route('support.attachments.show', $message))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'support_attachment.viewed', 'actor_user_id' => $operator->id]);

        $this->actingAs($user)->getJson(route('support.cases.show', $id))->assertJsonPath('messages.1.attachment.name', 'screen.jpg');
    }
}
