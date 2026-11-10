<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessPayment;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\SpendLimits;
use App\Domain\Business\Statements;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Statements (admins and finance viewers): list with month to date, a print-ready statement page, CSV. */
class StatementController extends BusinessController
{
    public function index(Request $request, Statements $statements, SpendLimits $limits): View
    {
        $member = $this->member($request, 'view_statements');
        $account = $member->account;
        $month = BusinessClock::monthKey();

        return view('business.invoices', [
            'member' => $member,
            'account' => $account,
            'can' => $this->can($member),
            'statements' => BusinessStatement::where('business_account_id', $account->id)->latest('period')->get(),
            'owed' => fn (BusinessStatement $s) => $statements->owed($s),
            'current' => $statements->figures($account, $month),
            'month' => $month,
            'monthLabel' => BusinessClock::monthLabel($month),
            'outstanding' => $limits->outstanding($account),
            'payments' => BusinessPayment::where('business_account_id', $account->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function show(Request $request, int $statement, Statements $statements): View
    {
        $member = $this->member($request, 'view_statements');
        $row = BusinessStatement::where('business_account_id', $member->business_account_id)->findOrFail($statement);

        return view('business.statement', ['statement' => $row, 'account' => $member->account, 'owed' => $statements->owed($row)]);
    }

    public function csv(Request $request, int $statement, Statements $statements): Response
    {
        $member = $this->member($request, 'view_statements');
        $row = BusinessStatement::where('business_account_id', $member->business_account_id)->findOrFail($statement);

        return $this->download($statements->csv($row->snapshot['lines'] ?? [], $row->currency), $row->number.'.csv');
    }

    public function monthCsv(Request $request, Statements $statements): Response
    {
        $member = $this->member($request, 'view_statements');
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? (string) $request->query('period') : BusinessClock::monthKey();
        $figures = $statements->figures($member->account, $period);

        return $this->download($statements->csv($figures['lines'], $member->account->currency), 'business-'.$period.'-to-date.csv');
    }

    private function download(string $csv, string $name): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
