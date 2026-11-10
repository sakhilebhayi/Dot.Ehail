<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Geo\Models\ServiceArea;
use App\Http\Controllers\Business\Concerns\ReadsRand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Ride policies: categories, days, hours, service areas, maximum fare, purpose, approval threshold, guests. */
class PolicyController extends BusinessController
{
    use ReadsRand;

    public function index(Request $request): View
    {
        $member = $this->member($request, 'view_policies');

        return view('business.policies', [
            'member' => $member,
            'account' => $member->account,
            'can' => $this->can($member),
            'policies' => BusinessPolicy::where('business_account_id', $member->business_account_id)->orderByDesc('is_default')->orderBy('name')->get(),
            'usage' => BusinessMember::where('business_account_id', $member->business_account_id)->where('status', BusinessMember::ACTIVE)
                ->selectRaw('policy_id, count(*) as people')->groupBy('policy_id')->pluck('people', 'policy_id'),
            'categories' => $this->categories(),
            'areas' => ServiceArea::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $member = $this->member($request, 'manage_policies');
        $data = $this->validated($request, $member->business_account_id);

        DB::transaction(function () use ($member, $data) {
            if ($data['is_default']) {
                BusinessPolicy::where('business_account_id', $member->business_account_id)->update(['is_default' => false]);
            }

            BusinessPolicy::create(['business_account_id' => $member->business_account_id] + $data);
        });

        return back()->with('success', 'Policy '.$data['name'].' created.');
    }

    public function update(Request $request, int $policy): RedirectResponse
    {
        $member = $this->member($request, 'manage_policies');
        $row = BusinessPolicy::where('business_account_id', $member->business_account_id)->findOrFail($policy);
        $data = $this->validated($request, $member->business_account_id, $row->id);

        DB::transaction(function () use ($member, $row, $data) {
            if ($data['is_default']) {
                BusinessPolicy::where('business_account_id', $member->business_account_id)->whereKeyNot($row->id)->update(['is_default' => false]);
            } elseif ($row->is_default) {
                $data['is_default'] = true; // one policy is always the default
            }

            $row->update($data);
        });

        return back()->with('success', 'Policy '.$row->name.' saved. It applies to rides requested from now on.');
    }

    /**
     * @return array<string, string> code => name
     */
    private function categories(): array
    {
        return RideCategory::query()->where('is_active', true)->orderBy('display_order')->orderBy('name')->pluck('name', 'key')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $accountId, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('business_policies', 'name')->where('business_account_id', $accountId)->ignore($ignore)],
            'is_default' => ['sometimes', 'boolean'],
            'allowed_categories' => ['nullable', 'array'],
            'allowed_categories.*' => ['string', Rule::in(array_keys($this->categories()))],
            'allowed_days' => ['nullable', 'array'],
            'allowed_days.*' => ['integer', 'between:1,7'],
            'hours_start' => ['nullable', 'required_with:hours_end', 'date_format:H:i'],
            'hours_end' => ['nullable', 'required_with:hours_start', 'date_format:H:i'],
            'service_area_ids' => ['nullable', 'array'],
            'service_area_ids.*' => ['integer', Rule::exists('service_areas', 'id')],
            'max_fare' => $this->randRule(),
            'purpose_required' => ['sometimes', 'boolean'],
            'approval_above' => $this->randRule(),
            'approval_window_minutes' => ['nullable', 'integer', 'between:5,1440'],
            'guest_bookings' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'There is already a policy with that name.']);

        $minutes = fn (?string $hm) => $hm === null ? null : ((int) substr($hm, 0, 2)) * 60 + (int) substr($hm, 3, 2);
        $days = array_values(array_unique(array_map('intval', $data['allowed_days'] ?? [])));
        sort($days);

        return [
            'name' => $data['name'],
            'is_default' => (bool) ($data['is_default'] ?? false),
            'allowed_categories' => ! empty($data['allowed_categories']) ? array_values($data['allowed_categories']) : null,
            'allowed_days' => $days !== [] && count($days) < 7 ? $days : null,
            'hours_start' => $minutes($data['hours_start'] ?? null),
            'hours_end' => $minutes($data['hours_end'] ?? null),
            'service_area_ids' => ! empty($data['service_area_ids']) ? array_values(array_map('intval', $data['service_area_ids'])) : null,
            'max_fare_minor' => $this->minorOrNull($data['max_fare'] ?? null),
            'purpose_required' => (bool) ($data['purpose_required'] ?? false),
            'approval_above_minor' => $this->minorOrNull($data['approval_above'] ?? null),
            'approval_window_minutes' => (int) ($data['approval_window_minutes'] ?? 30),
            'guest_bookings' => (bool) ($data['guest_bookings'] ?? false),
        ];
    }
}
