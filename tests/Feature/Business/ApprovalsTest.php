<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\Notifications\ApprovalDecidedNotification;
use App\Domain\Business\Notifications\ApprovalRequestedNotification;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * Approvals: request -> an approver of the same account decides within the window -> the traveller books
 * a matching ride once. Expiry, wrong approver, own request, mismatch and reuse are refused.
 */
class ApprovalsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    /**
     * @return array{0: BusinessMember, 1: BusinessMember, 2: BusinessMember}
     */
    private function world(): array
    {
        [$account, $admin] = $this->businessAccount();
        $policy = $this->policy($account, ['approval_above_minor' => 10_000, 'approval_window_minutes' => 30]);
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['policy_id' => $policy->id]);
        $manager = $this->member($account, BusinessMember::MANAGER);

        return [$admin, $manager, $traveller];
    }

    private function ask(BusinessMember $traveller, int $total = 15_000): BusinessApproval
    {
        $quote = $this->quotes->issue($traveller->user, ['totalMinor' => $total]);
        $this->actingAs($traveller->user)->postJson(route('business.api.approvals.store'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id, 'purpose' => 'Airport run'])
            ->assertCreated()->assertJsonPath('approval.status', 'pending');

        return BusinessApproval::latest('id')->first();
    }

    public function test_request_approve_then_book_once(): void
    {
        [$admin, $manager, $traveller] = $this->world();
        $approval = $this->ask($traveller);
        Notification::assertSentOnDemand(ApprovalRequestedNotification::class);

        $this->actingAs($manager->user)->post(route('business.approvals.approve', $approval->id), ['note' => 'Fine'])->assertRedirect();
        $this->assertSame(BusinessApproval::APPROVED, $approval->fresh()->status);
        Notification::assertSentOnDemand(ApprovalDecidedNotification::class);

        // A fresh quote for the same trip, slightly dearer (within the tolerance): booked without naming the approval.
        $this->requestBusinessRide($traveller, ['totalMinor' => 16_000])->assertCreated();
        $this->assertSame(BusinessApproval::USED, $approval->fresh()->status);
        $this->assertSame(Ride::latest('id')->value('id'), $approval->fresh()->ride_id);
        $this->assertSame($approval->id, BusinessTrip::whereNotNull('ride_id')->sole()->approval_id);

        $this->completeBusinessRide(Ride::latest('id')->value('id'), '160.00');
        $this->requestBusinessRide($traveller, ['totalMinor' => 15_000])->assertStatus(422)->assertJsonPath('error', 'business_approval_required');
    }

    public function test_a_ride_that_does_not_match_the_approval_is_refused(): void
    {
        [, $manager, $traveller] = $this->world();
        $approval = $this->ask($traveller);
        $this->actingAs($manager->user)->post(route('business.approvals.approve', $approval->id))->assertRedirect();

        $this->requestBusinessRide($traveller, ['totalMinor' => 30_000], ['approval_id' => $approval->id])->assertStatus(422)->assertJsonPath('error', 'business_approval_not_matching');
        $this->requestBusinessRide($traveller, ['totalMinor' => 15_000, 'dropoffLat' => -33.80, 'dropoffLng' => 18.60], ['approval_id' => $approval->id])->assertStatus(422)->assertJsonPath('error', 'business_approval_not_matching');
        $this->assertSame(BusinessApproval::APPROVED, $approval->fresh()->status);
    }

    public function test_pending_requests_expire_and_cannot_be_decided_late(): void
    {
        [, $manager, $traveller] = $this->world();
        $approval = $this->ask($traveller);

        Carbon::setTestNow(now()->addMinutes(31));
        $this->actingAs($manager->user)->post(route('business.approvals.approve', $approval->id))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(BusinessApproval::EXPIRED, $approval->fresh()->status);

        $second = $this->ask($traveller);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('business:sweep')->assertSuccessful();
        $this->assertSame(BusinessApproval::EXPIRED, $second->fresh()->status);
    }

    public function test_an_approved_request_runs_out_after_its_use_by_time(): void
    {
        [, $manager, $traveller] = $this->world();
        $approval = $this->ask($traveller);
        $this->actingAs($manager->user)->post(route('business.approvals.approve', $approval->id))->assertRedirect();

        Carbon::setTestNow(now()->addMinutes(121));
        $this->requestBusinessRide($traveller, ['totalMinor' => 15_000])->assertStatus(422)->assertJsonPath('error', 'business_approval_required');
    }

    public function test_only_other_approvers_of_the_same_account_can_decide(): void
    {
        [$admin, $manager, $traveller] = $this->world();
        $approval = $this->ask($traveller);
        $otherTraveller = $this->member($admin->account);
        [, $outsiderAdmin] = $this->businessAccount(['legal_name' => 'Other Co', 'registration_number' => '2020/000001/07']);

        $this->actingAs($otherTraveller->user)->post(route('business.approvals.approve', $approval->id))->assertForbidden();
        $this->actingAs($outsiderAdmin->user)->post(route('business.approvals.approve', $approval->id))->assertNotFound();
        $this->actingAs($traveller->user)->post(route('business.approvals.approve', $approval->id))->assertForbidden();

        // A manager asking for themselves never needs approval (approvers' own rides are self-authorised, D-1105).
        $this->requestBusinessRide($manager, ['totalMinor' => 50_000])->assertCreated();

        $this->actingAs($admin->user)->post(route('business.approvals.decline', $approval->id), ['note' => 'Use the shuttle'])->assertRedirect();
        $this->assertSame(BusinessApproval::DECLINED, $approval->fresh()->status);
        $this->assertSame('Use the shuttle', $approval->fresh()->decision_note);
        $this->actingAs($manager->user)->post(route('business.approvals.approve', $approval->id))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(BusinessApproval::DECLINED, $approval->fresh()->status);
    }

    public function test_travel_managers_see_only_their_departments_requests(): void
    {
        [$admin] = $this->world();
        $account = $admin->account;
        $sales = $this->department($account, 'Sales');
        $ops = $this->department($account, 'Ops');
        $policy = $this->policy($account, ['approval_above_minor' => 1000]);
        $salesTraveller = $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $sales->id, 'policy_id' => $policy->id]);
        $opsManager = $this->member($account, BusinessMember::MANAGER, ['department_id' => $ops->id]);

        $approval = $this->ask($salesTraveller, 5000);

        $this->actingAs($opsManager->user)->post(route('business.approvals.approve', $approval->id))->assertForbidden();
        $this->actingAs($admin->user)->post(route('business.approvals.approve', $approval->id))->assertRedirect();
        $this->assertSame(BusinessApproval::APPROVED, $approval->fresh()->status);
    }

    public function test_asking_for_a_ride_that_needs_no_approval_or_breaks_the_policy_is_refused(): void
    {
        [, , $traveller] = $this->world();
        $quote = $this->quotes->issue($traveller->user, ['totalMinor' => 5000]);

        $this->actingAs($traveller->user)->postJson(route('business.api.approvals.store'), ['profile_id' => $traveller->payment_method_id, 'quote_id' => $quote->id])
            ->assertStatus(409)->assertJsonPath('error', 'business_approval_not_needed');
    }
}
