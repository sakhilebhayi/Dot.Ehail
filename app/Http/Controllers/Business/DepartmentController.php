<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Models\BusinessDepartment;
use App\Http\Controllers\Business\Concerns\ReadsRand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Departments / cost centres with an optional monthly budget (account admins). */
class DepartmentController extends BusinessController
{
    use ReadsRand;

    public function store(Request $request): RedirectResponse
    {
        $member = $this->member($request, 'manage_departments');
        $data = $this->validated($request, $member->business_account_id);

        BusinessDepartment::create(['business_account_id' => $member->business_account_id] + $data);

        return back()->with('success', 'Department '.$data['name'].' added.');
    }

    public function update(Request $request, int $department): RedirectResponse
    {
        $member = $this->member($request, 'manage_departments');
        $row = BusinessDepartment::where('business_account_id', $member->business_account_id)->findOrFail($department);
        $row->update($this->validated($request, $member->business_account_id, $row->id));

        return back()->with('success', 'Department '.$row->name.' saved.');
    }

    public function destroy(Request $request, int $department): RedirectResponse
    {
        $member = $this->member($request, 'manage_departments');
        $row = BusinessDepartment::where('business_account_id', $member->business_account_id)->findOrFail($department);
        $row->delete();

        return back()->with('success', 'Department '.$row->name.' removed. Its people now have no department; past trips keep the name they were billed under.');
    }

    /**
     * @return array{name: string, cost_centre: ?string, monthly_budget_minor: ?int}
     */
    private function validated(Request $request, int $accountId, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('business_departments', 'name')->where('business_account_id', $accountId)->ignore($ignore)],
            'cost_centre' => ['nullable', 'string', 'max:30'],
            'monthly_budget' => $this->randRule(),
        ], ['name.unique' => 'There is already a department with that name.']);

        return ['name' => $data['name'], 'cost_centre' => $data['cost_centre'] ?? null, 'monthly_budget_minor' => $this->minorOrNull($data['monthly_budget'] ?? null)];
    }
}
