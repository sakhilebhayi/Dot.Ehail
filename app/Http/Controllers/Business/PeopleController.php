<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Abilities;
use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use App\Domain\Business\Models\BusinessSpendCounter;
use App\Http\Controllers\Business\Concerns\ReadsRand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** People on the account (invite, role, department, policy, allowance, remove) and departments. */
class PeopleController extends BusinessController
{
    use ReadsRand;

    public function index(Request $request): View
    {
        $member = $this->member($request, 'view_people');
        $accountId = $member->business_account_id;
        $month = BusinessClock::monthKey();
        $usage = BusinessSpendCounter::where('business_account_id', $accountId)->where('scope', BusinessSpendCounter::MEMBER)->where('period', $month)->get()->keyBy('scope_id');

        return view('business.people', [
            'member' => $member,
            'account' => $member->account,
            'can' => $this->can($member),
            'people' => BusinessMember::with(['user', 'department', 'policy'])->where('business_account_id', $accountId)
                ->whereIn('status', [BusinessMember::ACTIVE, BusinessMember::INVITED])
                ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderBy('name')->orderBy('email')->get(),
            'usage' => $usage,
            'departments' => BusinessDepartment::withCount(['members' => fn ($q) => $q->where('status', BusinessMember::ACTIVE)])->where('business_account_id', $accountId)->orderBy('name')->get(),
            'policies' => BusinessPolicy::where('business_account_id', $accountId)->orderByDesc('is_default')->orderBy('name')->get(),
            'roles' => array_intersect_key(BusinessMember::ROLES, array_flip(Abilities::assignableRoles($member))),
            'monthLabel' => BusinessClock::monthLabel($month),
        ]);
    }

    public function invite(Request $request, BusinessAccounts $accounts): RedirectResponse
    {
        $member = $this->member($request, 'manage_people');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_keys(BusinessMember::ROLES))],
            'department_id' => ['nullable', 'integer'],
            'policy_id' => ['nullable', 'integer'],
            'monthly_allowance' => $this->randRule(),
            'employee_ref' => ['nullable', 'string', 'max:40'],
        ]);

        $accounts->invite($member, [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'department_id' => $data['department_id'] ?? null,
            'policy_id' => $data['policy_id'] ?? null,
            'monthly_allowance_minor' => $this->minorOrNull($data['monthly_allowance'] ?? null),
            'employee_ref' => $data['employee_ref'] ?? null,
        ]);

        return back()->with('success', 'Invitation sent to '.strtolower($data['email']).'.');
    }

    public function update(Request $request, int $member, BusinessAccounts $accounts): RedirectResponse
    {
        $actor = $this->member($request, 'manage_people');
        $target = BusinessMember::where('business_account_id', $actor->business_account_id)->findOrFail($member);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(array_keys(BusinessMember::ROLES))],
            'department_id' => ['nullable', 'integer'],
            'policy_id' => ['nullable', 'integer'],
            'monthly_allowance' => $this->randRule(),
            'employee_ref' => ['nullable', 'string', 'max:40'],
        ]);

        $accounts->updateMember($actor, $target, array_filter([
            'role' => $data['role'] ?? null,
        ]) + [
            'department_id' => $data['department_id'] ?? null,
            'policy_id' => $data['policy_id'] ?? null,
            'monthly_allowance_minor' => $this->minorOrNull($data['monthly_allowance'] ?? null),
            'employee_ref' => $data['employee_ref'] ?? null,
        ]);

        return back()->with('success', 'Saved '.$target->displayName().'.');
    }

    public function destroy(Request $request, int $member, BusinessAccounts $accounts): RedirectResponse
    {
        $actor = $this->member($request, 'manage_people');
        $target = BusinessMember::where('business_account_id', $actor->business_account_id)->findOrFail($member);
        $accounts->remove($actor, $target);

        return back()->with('success', $target->status === BusinessMember::INVITED ? 'Invitation withdrawn.' : $target->displayName().' was removed. Their business profile no longer works; trips already booked still complete.');
    }
}
