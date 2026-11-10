<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\BusinessClock;
use App\Domain\Business\Collections;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPayment;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\SpendLimits;
use App\Domain\Business\Statements;
use App\Http\Controllers\Business\Concerns\ReadsRand;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Operators (`operator` middleware): the applications queue, approval with a credit limit and terms,
 * suspension and reactivation, payments received by EFT, and issuing a missed statement.
 */
class OpsBusinessController extends Controller
{
    use ReadsRand;

    public function index(Request $request, SpendLimits $limits): View
    {
        $status = in_array($request->query('status'), ['pending_review', 'active', 'suspended', 'declined'], true) ? $request->query('status') : null;

        return view('business.ops.index', [
            'accounts' => BusinessAccount::withCount(['members' => fn ($q) => $q->where('status', BusinessMember::ACTIVE)])
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->orderByRaw("CASE status WHEN 'pending_review' THEN 0 WHEN 'suspended' THEN 1 ELSE 2 END")->latest('id')->paginate(30)->withQueryString(),
            'status' => $status,
            'counts' => BusinessAccount::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'outstanding' => fn (BusinessAccount $a) => $limits->outstanding($a),
        ]);
    }

    public function show(int $account, SpendLimits $limits, Statements $statements): View
    {
        $row = BusinessAccount::with(['team', 'members.user'])->findOrFail($account);

        return view('business.ops.show', [
            'account' => $row,
            'admins' => $row->members->where('role', BusinessMember::ADMIN)->where('status', BusinessMember::ACTIVE),
            'outstanding' => $limits->outstanding($row),
            'creditAvailable' => $limits->creditAvailable($row),
            'statements' => BusinessStatement::where('business_account_id', $row->id)->latest('period')->get(),
            'owed' => fn (BusinessStatement $s) => $statements->owed($s),
            'payments' => BusinessPayment::where('business_account_id', $row->id)->latest('id')->limit(20)->get(),
            'lastMonth' => BusinessClock::previousMonthKey(),
        ]);
    }

    public function approve(Request $request, int $account, BusinessAccounts $accounts): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        abort_unless(in_array($row->status, [BusinessAccount::PENDING, BusinessAccount::DECLINED], true), 409, 'Only an application waiting for review can be approved.');
        $data = $this->termsInput($request, true);

        $accounts->approve($row, $request->user(), $data['credit_limit_minor'], $data['terms_days'], $data['grace_days'], $data['note']);

        return back()->with('success', $row->displayName().' is active.');
    }

    public function decline(Request $request, int $account, BusinessAccounts $accounts): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        abort_unless($row->status === BusinessAccount::PENDING, 409, 'Only an application waiting for review can be declined.');
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $accounts->decline($row, $request->user(), $data['note']);

        return back()->with('success', 'Application declined.');
    }

    public function suspend(Request $request, int $account, BusinessAccounts $accounts): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $accounts->suspend($row, 'operator', $data['note']);

        return back()->with('success', $row->displayName().' is suspended for new rides. Booked trips still complete.');
    }

    public function reactivate(Request $request, int $account, BusinessAccounts $accounts): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        $accounts->reactivate($row, $request->input('note'));

        return back()->with('success', $row->displayName().' is active again.');
    }

    public function terms(Request $request, int $account): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        $data = $this->termsInput($request, false);
        $row->forceFill(['credit_limit_minor' => $data['credit_limit_minor'], 'terms_days' => $data['terms_days'], 'grace_days' => $data['grace_days']])->save();

        return back()->with('success', 'Credit terms saved.');
    }

    public function recordPayment(Request $request, int $account, Collections $collections): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $collections->recordEft($row, $request->user(), (int) $this->minorOrNull($data['amount']), trim($data['reference']), $data['note'] ?? null);

        return back()->with('success', 'Payment recorded and posted to the ledger.');
    }

    public function issueStatement(Request $request, int $account, Statements $statements): RedirectResponse
    {
        $row = BusinessAccount::findOrFail($account);
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $statement = $statements->issue($row, $data['period']);

        return back()->with('success', 'Statement '.$statement->number.' issued.');
    }

    /**
     * @return array{credit_limit_minor: int, terms_days: int, grace_days: int, note: ?string}
     */
    private function termsInput(Request $request, bool $withNote): array
    {
        $data = $request->validate([
            'credit_limit' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'terms_days' => ['required', 'integer', 'between:0,90'],
            'grace_days' => ['required', 'integer', 'between:0,60'],
            'note' => [$withNote ? 'nullable' : 'prohibited', 'string', 'max:500'],
        ]);

        return ['credit_limit_minor' => (int) $this->minorOrNull($data['credit_limit']), 'terms_days' => (int) $data['terms_days'], 'grace_days' => (int) $data['grace_days'], 'note' => $data['note'] ?? null];
    }
}
