<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Abilities;
use App\Domain\Business\Approvals;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** The approvals queue (approvers) and a traveller's own requests. */
class ApprovalController extends BusinessController
{
    public function index(Request $request, Approvals $approvals): View
    {
        $member = $this->member($request);
        $approvals->expireDue();

        $canDecide = Abilities::allows($member, 'decide_approvals');

        return view('business.approvals', [
            'member' => $member,
            'account' => $member->account,
            'can' => $this->can($member),
            'queue' => $canDecide ? self::queueFor($member) : collect(),
            'history' => BusinessApproval::with(['requester.user', 'traveller.user'])
                ->where('business_account_id', $member->business_account_id)
                ->when(! $canDecide, fn ($q) => $q->where('requester_member_id', $member->id))
                ->whereNot('status', BusinessApproval::PENDING)
                ->latest('updated_at')->limit(50)->get(),
            'mine' => BusinessApproval::where('requester_member_id', $member->id)->where('status', BusinessApproval::PENDING)->latest('id')->get(),
        ]);
    }

    public function approve(Request $request, int $approval, Approvals $approvals): RedirectResponse
    {
        return $this->decide($request, $approval, $approvals, true);
    }

    public function decline(Request $request, int $approval, Approvals $approvals): RedirectResponse
    {
        return $this->decide($request, $approval, $approvals, false);
    }

    public function withdraw(Request $request, int $approval, Approvals $approvals): RedirectResponse
    {
        $member = $this->member($request);
        $approvals->withdraw($this->find($member, $approval), $member);

        return back()->with('success', 'Request withdrawn.');
    }

    /**
     * Pending requests this member may decide: never their own; account admins see all, travel managers
     * those of riders in their department (or all when either side has no department).
     *
     * @return Collection<int, BusinessApproval>
     */
    public static function queueFor(BusinessMember $member): Collection
    {
        return BusinessApproval::with(['requester.user', 'requester.department', 'traveller.user', 'traveller.department'])
            ->where('business_account_id', $member->business_account_id)
            ->where('status', BusinessApproval::PENDING)
            ->where('decide_by', '>', now())
            ->whereNot('requester_member_id', $member->id)
            ->orderBy('decide_by')->get()
            ->filter(function (BusinessApproval $a) use ($member) {
                if ($member->role === BusinessMember::ADMIN) {
                    return true;
                }

                $riderDepartment = ($a->traveller ?? $a->requester)?->department_id;

                return $member->department_id === null || $riderDepartment === null || $riderDepartment === $member->department_id;
            })->values();
    }

    private function decide(Request $request, int $approvalId, Approvals $approvals, bool $approve): RedirectResponse
    {
        $member = $this->member($request, 'decide_approvals');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $approval = $this->find($member, $approvalId);

        if (! self::queueFor($member)->contains('id', $approval->id) && $approval->status === BusinessApproval::PENDING && $approval->decide_by->isFuture()) {
            abort(403, 'This request belongs to another department\'s travel manager.');
        }

        $approvals->decide($approval, $member, $approve, $data['note'] ?? null);

        return back()->with('success', $approve ? 'Approved. The traveller can book it now.' : 'Declined. The traveller has been told.');
    }

    private function find(BusinessMember $member, int $id): BusinessApproval
    {
        return BusinessApproval::where('business_account_id', $member->business_account_id)->findOrFail($id);
    }
}
