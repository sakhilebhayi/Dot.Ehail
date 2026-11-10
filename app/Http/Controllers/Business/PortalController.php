<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessCharge;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessSpendCounter;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\SpendLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** `/business`: the overview (or the application form for someone with no business account yet). */
class PortalController extends BusinessController
{
    public function home(Request $request, BusinessAccounts $accounts, SpendLimits $limits): View
    {
        $member = $this->currentMember($request);
        $user = $request->user();

        if ($member === null) {
            return view('business.apply', [
                'teams' => $accounts->linkableTeams($user),
                'invitations' => $accounts->invitationsFor($user),
                'countries' => config('ehail_business.countries'),
            ]);
        }

        $account = $member->account;
        $month = BusinessClock::monthKey();
        [$start, $end] = BusinessClock::monthBounds($month);
        $can = $this->can($member);

        $data = [
            'member' => $member,
            'account' => $account,
            'can' => $can,
            'month' => $month,
            'monthLabel' => BusinessClock::monthLabel($month),
            'memberships' => $this->memberships($request),
            'invitations' => $accounts->invitationsFor($user),
        ];

        if ($can['view_overview']) {
            $departments = BusinessDepartment::where('business_account_id', $account->id)->orderBy('name')->get();
            $counters = BusinessSpendCounter::where('business_account_id', $account->id)->where('scope', BusinessSpendCounter::DEPARTMENT)->where('period', $month)->get()->keyBy('scope_id');

            $data += [
                // Same basis as the department budget lines (D-1912): a trip's charge counts in the month of the trip
                // (its spend month, as the budget counters do); a charge with no trip (an adjustment) by when it was
                // recorded. Was "recorded in the month" only, so the headline said R0.00 next to a department at R48.00.
                'spent' => (int) BusinessCharge::where('business_account_id', $account->id)
                    ->where(fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('spend_month', $month))
                        ->orWhere(fn ($loose) => $loose->whereNull('business_trip_id')->where('recorded_at', '>=', $start)->where('recorded_at', '<', $end)))
                    ->sum('amount_minor'),
                'held' => (int) BusinessSpendCounter::where('scope', BusinessSpendCounter::CREDIT)->where('scope_id', $account->id)->where('period', 'open')->value('reserved_minor'),
                'outstanding' => $limits->outstanding($account),
                'creditAvailable' => $limits->creditAvailable($account),
                'budgets' => $departments->filter(fn ($d) => $d->monthly_budget_minor !== null)->map(fn (BusinessDepartment $d) => [
                    'department' => $d,
                    'spent' => (int) ($counters->get($d->id)?->spent_minor ?? 0),
                    'held' => (int) ($counters->get($d->id)?->reserved_minor ?? 0),
                    'budget' => (int) $d->monthly_budget_minor,
                ])->values(),
                'totalBudget' => $departments->sum(fn ($d) => (int) ($d->monthly_budget_minor ?? 0)),
                'rideCount' => BusinessTrip::where('business_account_id', $account->id)->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED])->where('spend_month', $month)->count(),
                'pendingApprovals' => $can['decide_approvals'] ? $this->pendingFor($member)->take(5) : collect(),
                'pendingCount' => $can['decide_approvals'] ? $this->pendingFor($member)->count() : 0,
                'recentTrips' => BusinessTrip::with(['ride', 'traveller.user', 'booker.user'])->where('business_account_id', $account->id)
                    ->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED, BusinessTrip::RELEASED])->latest('booked_at')->limit(6)->get(),
                'latestStatement' => $can['view_statements'] ? BusinessStatement::where('business_account_id', $account->id)->latest('period')->first() : null,
                'peopleCount' => BusinessMember::where('business_account_id', $account->id)->where('status', BusinessMember::ACTIVE)->count(),
                'invitedCount' => BusinessMember::where('business_account_id', $account->id)->where('status', BusinessMember::INVITED)->count(),
            ];
        }

        $data += [
            'allowanceUsed' => $member->monthly_allowance_minor !== null ? $limits->used(BusinessSpendCounter::MEMBER, $member->id, $month) : null,
            'policy' => $member->effectivePolicy(),
            'myTrips' => BusinessTrip::with(['ride'])->where('business_account_id', $account->id)
                ->where(fn ($q) => $q->where('traveller_member_id', $member->id)->orWhere('booker_member_id', $member->id))
                ->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED, BusinessTrip::RELEASED])->latest('booked_at')->limit(5)->get(),
            'myApprovals' => BusinessApproval::where('requester_member_id', $member->id)->latest('id')->limit(5)->get(),
        ];

        return view('business.home', $data);
    }

    public function apply(Request $request, BusinessAccounts $accounts): RedirectResponse
    {
        $countries = array_keys((array) config('ehail_business.countries'));

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:160'],
            'trading_name' => ['nullable', 'string', 'max:160'],
            'registration_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/\- ]+$/'],
            'vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ]+$/'],
            'billing_email' => ['required', 'email', 'max:190'],
            'address_line1' => ['required', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'country' => ['required', Rule::in($countries)],
            'billing_mode' => ['required', Rule::in(['invoice', 'card'])],
            'team_id' => ['nullable', 'integer'],
        ], [
            'registration_number.regex' => 'Use letters, digits, spaces, slashes or dashes, as on the registration certificate.',
            'vat_number.regex' => 'A VAT number is digits only.',
        ]);

        $account = $accounts->apply($request->user(), $data);
        $request->session()->put(self::SESSION_KEY, $account->id);

        return redirect()->route('business.home')->with('success', 'Application sent. Dot.Ehail reviews new business accounts; you can set up people and policies meanwhile.');
    }

    public function switchAccount(Request $request): RedirectResponse
    {
        $id = (int) $request->input('account_id');

        if (BusinessMember::where('user_id', $request->user()->id)->where('business_account_id', $id)->where('status', BusinessMember::ACTIVE)->exists()) {
            $request->session()->put(self::SESSION_KEY, $id);
        }

        return redirect()->route('business.home');
    }

    /**
     * @return Collection<int, BusinessApproval>
     */
    private function pendingFor(BusinessMember $member): Collection
    {
        return ApprovalController::queueFor($member);
    }

    /**
     * @return Collection<int, BusinessMember>
     */
    private function memberships(Request $request): Collection
    {
        return BusinessMember::with('account')->where('user_id', $request->user()->id)->where('status', BusinessMember::ACTIVE)->get();
    }
}
