<?php

namespace Tests\Feature\Ops;

use App\Domain\Safety\Models\SafetyIncident;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * An SOS takes over the live board and the incident page leads with who owns it (R-46 polish, D-2202): the rail is
 * for safety readers only, "nobody owns it" is said in words, and taking ownership is one audited action.
 */
class SosTakeoverTest extends TestCase
{
    use CreatesSafetyFixtures, OpsFixtures {
        OpsFixtures::makeOperator insteadof CreatesSafetyFixtures;
    }
    use RefreshDatabase;

    private function sos(): SafetyIncident
    {
        Mail::fake();
        [$ride, $passenger] = $this->makeActiveRide();
        $this->actingAs($passenger)->postJson(route('safety.rides.sos', $ride), ['lat' => -26.2041, 'lng' => 28.0473, 'accuracy_m' => 12])->assertCreated();

        return SafetyIncident::firstOrFail();
    }

    public function test_the_live_feed_carries_the_emergency_for_safety_readers_only(): void
    {
        $incident = $this->sos();

        $item = $this->actingAs($this->operatorWith(['admin']))->getJson(route('ops.live.feed'))->assertOk()->json('takeover.0');
        $this->assertSame($incident->id, $item['id']);
        $this->assertTrue($item['is_sos']);
        $this->assertNull($item['owner']);
        $this->assertSame('Nobody owns it and nobody has acknowledged it', $item['owner_text']);
        $this->assertStringContainsString('SAST', $item['opened_text']);
        $this->assertDoesNotMatchRegularExpression('/-?\d{1,2}\.\d{3,}/', $item['place']);

        // A dispatcher sees the board but not safety incidents: no rail, no incident details.
        $this->actingAs($this->operatorWith(['dispatcher']))->getJson(route('ops.live.feed'))->assertOk()->assertJsonPath('takeover', []);
    }

    public function test_the_live_board_draws_the_rail_with_an_alarm_that_is_off_until_clicked(): void
    {
        $incident = $this->sos();

        $this->actingAs($this->operatorWith(['admin']))->get(route('ops.live'))->assertOk()
            ->assertSee('data-live-takeover', false)
            ->assertSee('1 emergency open, 1 with nobody owning it')
            ->assertSee('Alarm sound off')
            ->assertSee($incident->reference)
            ->assertSee('Open and take it');
    }

    public function test_the_incident_page_leads_with_ownership_and_names_the_place_before_the_coordinates(): void
    {
        $incident = $this->sos();
        $admin = $this->operatorWith(['admin']);

        $html = (string) $this->actingAs($admin)->get(route('ops.safety.show', $incident->id))->assertOk()
            ->assertSee('Nobody owns it and nobody has acknowledged it')
            ->assertSee('Acknowledge and take ownership')
            ->assertSee('GPS for emergency services')
            ->assertSee('data-eh-stopmap', false)
            ->getContent();

        $this->assertLessThan(strpos($html, 'GPS for emergency services'), strpos($html, 'ops-where__place'), 'The place in words comes before the coordinates.');

        // A role that reads incidents but may not act sees who owns it, with no action it cannot take.
        $this->actingAs($this->operatorWith(['ops_agent']))->get(route('ops.safety.show', $incident->id))->assertOk()
            ->assertSee('Nobody owns it and nobody has acknowledged it')
            ->assertSee('Your role reads safety incidents')
            ->assertDontSee('Acknowledge and take ownership');
    }

    public function test_taking_ownership_is_one_action_and_it_is_audited(): void
    {
        $incident = $this->sos();
        $admin = $this->operatorWith(['admin']);

        $this->actingAs($admin)->postJson(route('safety.ops.incidents.acknowledge', $incident->id))->assertSuccessful();

        $incident->refresh();
        $this->assertSame($admin->id, $incident->owner_id);
        $log = AuditLog::query()->where('action', 'safety_incident.acknowledged')->where('subject_id', $incident->id)->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($admin->id, $log->meta['owner_id']);

        $this->actingAs($admin)->get(route('ops.safety.show', $incident->id))->assertOk()
            ->assertSee('You own this incident')
            ->assertDontSee('Acknowledge and take ownership');
        $this->actingAs($admin)->getJson(route('ops.live.feed'))->assertJsonPath('takeover.0.owner', $admin->name);

        $other = $this->operatorWith(['admin']);
        $this->actingAs($other)->get(route('ops.safety.show', $incident->id))->assertOk()->assertSee('Take ownership');
        $this->actingAs($other)->postJson(route('safety.ops.incidents.assign', $incident->id), ['owner_id' => $other->id])->assertSuccessful();
        $this->assertSame($other->id, $incident->fresh()->owner_id);
        $this->assertSame($admin->id, AuditLog::query()->where('action', 'safety_incident.assigned')->firstOrFail()->meta['previous_owner_id']);
    }
}
