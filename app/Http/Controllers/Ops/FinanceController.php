<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Ledger\Models\LedgerJournal;
use App\Domain\Ledger\Reconciliation;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Insights\LedgerPeriods;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Money;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Finance: payments (search, detail, refunds), payout batches (draft, export, settle, fail, reverse), the
 * reconciliation report with open finance issues, and the ledger (balances by account, journals, revenue and
 * commission by period from the ledger itself). Reads here; every action posts to the money API.
 */
class FinanceController extends Controller
{
    public function payments(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['initiated', 'pending', 'paid', 'failed', 'expired', 'unmatched', 'awaiting_cash'])],
            'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $q = trim((string) ($validated['q'] ?? ''));

        $payments = Payment::query()->with(['payer:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $s) => $query->where('status', $s))
            ->when($validated['from'] ?? null, fn ($query, $d) => $query->where('created_at', '>=', $d.' 00:00:00'))
            ->when($validated['to'] ?? null, fn ($query, $d) => $query->where('created_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('reference', 'like', '%'.$q.'%')
                ->when(ctype_digit($q), fn ($i) => $i->orWhere('ride_id', (int) $q))))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('ops.finance.payments', [
            'payments' => $payments,
            'filters' => $validated,
            'openIssues' => FinanceIssue::query()->where('status', 'open')->count(),
        ]);
    }

    public function payment(Request $request, Payment $payment, OpsAccess $access): View
    {
        $payment->load(['refunds', 'payer:id,name,email', 'ride:id,status,pickup_address,dropoff_address,driver_id']);

        return view('ops.finance.payment', [
            'payment' => $payment,
            'journals' => LedgerJournal::query()->with('entries')->where('payment_id', $payment->id)->orderBy('id')->get(),
            'canRefund' => $access->can($request->user(), OpsPermission::FINANCE_REFUND),
            'reasons' => array_diff_key(Refund::REASONS, ['card_setup_verification' => true]),
            'refundable' => max(0, $payment->amountMinor() - (int) $payment->refunds->whereIn('status', ['pending', 'processed', 'processing'])->sum('amount_minor')),
        ]);
    }

    public function payouts(Request $request, OpsAccess $access): View
    {
        return view('ops.finance.payouts', [
            'batches' => PayoutBatch::query()->orderByDesc('period_end')->orderByDesc('id')->paginate(25),
            'canPayout' => $access->can($request->user(), OpsPermission::FINANCE_PAYOUTS),
            'transfersOn' => (bool) config('money.payouts.paystack_transfers'),
        ]);
    }

    public function batch(Request $request, PayoutBatch $batch, OpsAccess $access): View
    {
        return view('ops.finance.batch', [
            'batch' => $batch->load(['payouts.payee:id,name']),
            'canPayout' => $access->can($request->user(), OpsPermission::FINANCE_PAYOUTS),
            'transfersOn' => (bool) config('money.payouts.paystack_transfers'),
        ]);
    }

    public function reconciliation(Request $request, Reconciliation $reconciliation, OpsAccess $access): View
    {
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'issues' => ['nullable', Rule::in(['open', 'resolved', 'all'])]]);
        $from = CarbonImmutable::parse($validated['from'] ?? now()->subDays(7)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $issueStatus = $validated['issues'] ?? 'open';

        return view('ops.finance.reconciliation', [
            'report' => $reconciliation->report($from, $to),
            'from' => $from,
            'to' => $to,
            'issueStatus' => $issueStatus,
            'issues' => FinanceIssue::query()->when($issueStatus !== 'all', fn ($q) => $q->where('status', $issueStatus))->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('id')->paginate(50)->withQueryString(),
            'canManage' => $access->can($request->user(), OpsPermission::FINANCE_MANAGE),
        ]);
    }

    public function ledger(Request $request, Ledger $ledger, LedgerPeriods $periods): View
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'grain' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'account' => ['nullable', 'string', 'max:80'],
        ]);
        $currency = Money::currency();
        $from = CarbonImmutable::parse($validated['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $grain = $validated['grain'] ?? 'week';

        return view('ops.finance.ledger', [
            'currency' => $currency,
            'balances' => $ledger->trialBalance($currency),
            'periods' => $periods->revenue($from, $to, $grain, $currency),
            'from' => $from,
            'to' => $to,
            'grain' => $grain,
            'account' => $validated['account'] ?? null,
            'journals' => LedgerJournal::query()->with('entries')
                ->when($validated['account'] ?? null, fn ($q, $account) => $q->whereHas('entries', fn ($e) => $e->where('account', $account)))
                ->whereBetween('posted_at', [$from, $to])
                ->orderByDesc('id')->paginate(30)->withQueryString(),
            'chart' => Account::CHART,
        ]);
    }
}
