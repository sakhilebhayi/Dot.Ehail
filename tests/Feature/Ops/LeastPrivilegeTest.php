<?php

namespace Tests\Feature\Ops;

use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * A role cannot reach another role's data, over HTTP, including the routes and policies other domains guard by the
 * operator flag alone (identity documents, support cases, driver positions, payouts).
 */
class LeastPrivilegeTest extends TestCase
{
    use OpsFixtures;
    use RefreshDatabase;

    private function document(array $w): VerificationDocument
    {
        Storage::fake('driver-documents');
        Storage::disk('driver-documents')->put('verification/id.jpg', $this->jpegWithExif());

        return VerificationDocument::query()->create([
            'user_id' => $w['driver']->id, 'driver_profile_id' => $w['profile']->id, 'market' => 'ZA', 'subject' => 'driver', 'type' => 'identity_document',
            'status' => 'pending', 'disk' => 'driver-documents', 'file_path' => 'verification/id.jpg', 'original_filename' => 'id.jpg', 'mime' => 'image/jpeg',
            'size_bytes' => 100, 'sha256' => str_repeat('b', 64), 'submitted_at' => now(),
        ]);
    }

    public function test_finance_cannot_read_identity_documents_but_a_reviewer_can_and_the_view_is_logged(): void
    {
        $w = $this->seedOpsWorld();
        $doc = $this->document($w);
        $finance = $this->operatorWith(['finance']);
        $reviewer = $this->operatorWith(['verification_reviewer']);

        $this->actingAs($finance)->get(route('verification.documents.file', $doc))->assertNotFound();
        $this->actingAs($finance)->get(route('ops.verification.show', $doc))->assertForbidden();
        $this->actingAs($finance)->getJson(route('safety.ops.verification.show', $doc))->assertForbidden();
        $this->assertFalse(Gate::forUser($finance)->allows('view', $doc));

        $this->actingAs($reviewer)->get(route('ops.verification.show', $doc))->assertOk()->assertSee(route('verification.documents.file', $doc), false);
        $this->actingAs($reviewer)->get(route('verification.documents.file', $doc))->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'verification_document.viewed')->where('actor_user_id', $reviewer->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'ops.viewed.verification_document')->where('actor_user_id', $reviewer->id)->exists());

        // The driver still opens their own document whatever the operator rules say.
        $this->actingAs($w['driver'])->get(route('verification.documents.file', $doc))->assertOk();
    }

    public function test_reviewers_never_see_earnings_or_payouts_and_finance_does(): void
    {
        $w = $this->seedOpsWorld();
        $batch = PayoutBatch::query()->create(['currency' => 'ZAR', 'period_end' => now()->subDay()->toDateString(), 'status' => 'draft', 'method' => 'manual', 'payout_count' => 1, 'total_minor' => 9600]);
        $payout = Payout::query()->create(['payout_batch_id' => $batch->id, 'payee_user_id' => $w['driver']->id, 'payee_type' => 'driver', 'currency' => 'ZAR', 'earnings_minor' => 9600, 'cash_offset_minor' => 0, 'amount_minor' => 9600, 'status' => 'pending', 'reference' => 'EHP-TEST-1', 'composition' => []]);
        $reviewer = $this->operatorWith(['verification_reviewer']);
        $finance = $this->operatorWith(['finance']);

        $this->actingAs($reviewer)->get(route('ops.drivers.show', $w['driver']->id))->assertOk()->assertDontSee('Earnings and payouts')->assertDontSee('EHP-TEST-1')->assertSee('Documents');
        $this->assertFalse(Gate::forUser($reviewer)->allows('money.view-payout', $payout));
        $this->actingAs($reviewer)->getJson(route('money.ops.batch', $batch))->assertForbidden();
        $this->actingAs($reviewer)->get(route('ops.finance.batch', $batch))->assertForbidden();

        $this->actingAs($finance)->get(route('ops.drivers.show', $w['driver']->id))->assertOk()->assertSee('Earnings and payouts')->assertSee('EHP-TEST-1')->assertDontSee('Opening one is logged');
        $this->assertTrue(Gate::forUser($finance)->allows('money.view-payout', $payout));
        $this->actingAs($finance)->get(route('ops.finance.batch', $batch))->assertOk()->assertSee('EHP-TEST-1');
    }

    public function test_support_cases_and_driver_positions_need_their_roles_even_outside_the_operator_routes(): void
    {
        $w = $this->seedOpsWorld();
        $dispatcher = $this->operatorWith(['dispatcher']);
        $support = $this->operatorWith(['support_agent']);
        $pricing = $this->operatorWith(['pricing_manager']);

        // The participant page: a dispatcher is stopped by the role guard (403), not by the case lookup.
        $this->actingAs($dispatcher)->getJson(route('support.cases.show', $w['case']))->assertForbidden();
        $this->actingAs($dispatcher)->getJson(route('support.ops.cases.show', $w['case']))->assertForbidden();
        $this->actingAs($support)->getJson(route('support.ops.cases.show', $w['case']))->assertOk();
        $this->actingAs($support)->get(route('ops.support.show', $w['case']))->assertOk()->assertSee('Left my phone');
        $this->actingAs($w['passenger'])->getJson(route('support.cases.show', $w['case']))->assertOk();

        $w['searching']->forceFill(['status' => 'driver_assigned', 'driver_id' => $w['driver']->id, 'accepted_at' => now()])->save();
        $this->actingAs($pricing)->getJson(route('geo.rides.driver-location', $w['searching']))->assertForbidden();
        $this->assertNotSame(403, $this->actingAs($dispatcher)->getJson(route('geo.rides.driver-location', $w['searching']))->status());
    }

    public function test_live_positions_are_only_in_the_feed_for_roles_that_may_see_them(): void
    {
        $this->seedOpsWorld();
        $agent = $this->operatorWith(['ops_agent']);
        $dispatcher = $this->operatorWith(['dispatcher']);

        $agentFeed = $this->actingAs($agent)->getJson(route('ops.live.feed'))->assertOk()->json();
        $this->assertFalse($agentFeed['positions_allowed']);
        $this->assertSame([], $agentFeed['drivers']);
        $this->assertArrayHasKey('incidents', $agentFeed['counts']);

        $feed = $this->actingAs($dispatcher)->getJson(route('ops.live.feed'))->assertOk()->json();
        $this->assertTrue($feed['positions_allowed']);
        $this->assertCount(1, $feed['drivers']);
        $this->assertArrayNotHasKey('incidents', $feed['counts'], 'a dispatcher has no safety role');

        $etag = $this->actingAs($dispatcher)->getJson(route('ops.live.feed'))->headers->get('ETag');
        $this->actingAs($dispatcher)->get(route('ops.live.feed'), ['If-None-Match' => $etag, 'Accept' => 'application/json'])->assertStatus(304);
    }

    public function test_interventions_are_offered_and_accepted_only_for_the_dispatcher(): void
    {
        $w = $this->seedOpsWorld();
        $agent = $this->operatorWith(['ops_agent']);
        $dispatcher = $this->operatorWith(['dispatcher']);
        $ride = $w['searching'];

        $this->actingAs($agent)->get(route('ops.rides.show', $ride->id))->assertOk()->assertDontSee('Intervene')->assertDontSee(route('ehail.dispatch.ops.rides.force-cancel', $ride->id), false);
        $this->actingAs($agent)->postJson(route('ehail.dispatch.ops.rides.force-cancel', $ride->id), ['reason' => 'Testing the agent role'])->assertForbidden();
        $this->assertSame('searching', $ride->fresh()->status);

        $this->actingAs($dispatcher)->get(route('ops.rides.show', $ride->id))->assertOk()->assertSee('Intervene')->assertSee(route('ehail.dispatch.ops.rides.force-cancel', $ride->id), false);
        $this->actingAs($dispatcher)->postJson(route('ehail.dispatch.ops.rides.force-cancel', $ride->id), ['reason' => 'Passenger asked by phone'])->assertOk();
        $this->assertSame('cancelled_by_operator', $ride->fresh()->status);
    }

    public function test_the_ops_home_and_the_navigation_follow_the_role(): void
    {
        $finance = $this->operatorWith(['finance']);
        $this->actingAs($finance)->get(route('ops.home'))->assertRedirect(route('ops.rides'));

        $pricing = $this->operatorWith(['pricing_manager']);
        $this->actingAs($pricing)->get(route('ops.home'))->assertRedirect(route('ops.pricing'));
        $page = $this->actingAs($pricing)->get(route('ops.pricing'))->assertOk();
        $page->assertSee(route('ops.insights'), false)->assertDontSee(route('ops.finance'), false)->assertDontSee(route('ops.verification'), false)->assertDontSee(route('ops.roles'), false);
    }

    public function test_the_old_operator_pages_redirect_into_the_centre(): void
    {
        $operator = $this->makeOperator();
        $this->actingAs($operator)->get('/operator/rides')->assertRedirect(route('ops.rides'));
        $this->actingAs($operator)->get('/operator/fleets')->assertRedirect(route('ops.fleets'));
    }

    public function test_views_of_sensitive_records_are_audited_once_per_ten_minutes(): void
    {
        $w = $this->seedOpsWorld();
        $agent = $this->operatorWith(['ops_agent']);

        $this->actingAs($agent)->get(route('ops.rides.show', $w['completed']->id))->assertOk();
        $this->actingAs($agent)->get(route('ops.rides.show', $w['completed']->id))->assertOk();
        $this->actingAs($agent)->get(route('ops.safety.show', $w['incident']))->assertOk();

        $this->assertSame(1, AuditLog::query()->where('action', 'ops.viewed.ride')->where('actor_user_id', $agent->id)->where('subject_id', $w['completed']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'ops.viewed.safety_incident')->where('actor_user_id', $agent->id)->count());

        $this->travel(11)->minutes();
        $this->actingAs($agent)->get(route('ops.rides.show', $w['completed']->id))->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', 'ops.viewed.ride')->where('actor_user_id', $agent->id)->count());
    }
}
