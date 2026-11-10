<?php

namespace App\Http\Controllers\Money;

use App\Domain\Earnings\Incentives\IncentiveEngine;
use App\Domain\Earnings\Models\IncentiveProgram;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\Reconciliation;
use App\Domain\Ledger\UnbalancedJournal;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Money;
use App\Domain\Payments\Refunds;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance operations API (platform operators only; routes carry the "operator" middleware).
 */
class OpsMoneyController extends Controller
{
    public function payments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'max:20'],
            'purpose' => ['sometimes', 'string', 'max:24'],
            'ride_id' => ['sometimes', 'integer'],
        ]);

        $query = Payment::query()->orderByDesc('id');

        foreach (['status', 'purpose', 'ride_id'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }

        return response()->json($query->paginate(50)->through(fn (Payment $p) => $this->paymentArray($p)));
    }

    public function payment(Payment $payment): JsonResponse
    {
        return response()->json($this->paymentArray($payment) + [
            'refunds' => $payment->refunds()->orderBy('id')->get()->map(fn (Refund $r) => $this->refundArray($r))->values(),
            'summary' => $payment->summary,
        ]);
    }

    public function refund(Request $request, Payment $payment, Refunds $refunds): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(array_diff(array_keys(Refund::REASONS), ['card_setup_verification']))],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
            'charge_back_driver' => ['sometimes', 'boolean'],
        ]);

        try {
            $refund = $refunds->request($payment, $request->user(), (int) $data['amount_minor'], $data['reason'], $data['note'] ?? null, (bool) ($data['charge_back_driver'] ?? false));
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['refund' => $this->refundArray($refund)], 201);
    }

    public function refunds(): JsonResponse
    {
        return response()->json(Refund::orderByDesc('id')->paginate(50)->through(fn (Refund $r) => $this->refundArray($r)));
    }

    public function batches(): JsonResponse
    {
        return response()->json(PayoutBatch::orderByDesc('period_end')->paginate(25));
    }

    public function draftBatch(Request $request, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['period_end' => ['required', 'date_format:Y-m-d', 'before:today']]);

        return response()->json(['batch' => $payouts->draftBatch(CarbonImmutable::parse($data['period_end']), $request->user())], 201);
    }

    public function batch(PayoutBatch $batch, PayoutService $payouts): JsonResponse
    {
        return response()->json([
            'batch' => $batch,
            'transfers_enabled' => $payouts->transfersEnabled(),
            'payouts' => $batch->payouts()->with(['payee:id,name', 'account'])->orderBy('id')->get()->map(fn (Payout $p) => $p->toPublicArray() + ['payee_name' => $p->payee?->name])->values(),
        ]);
    }

    public function exportBatch(Request $request, PayoutBatch $batch, PayoutService $payouts): StreamedResponse
    {
        return DriverMoneyController::csv($payouts->exportRows($batch, $request->user()), 'payout-batch-'.$batch->id.'.csv');
    }

    public function markBatchPaid(Request $request, PayoutBatch $batch, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['payout_ids' => ['sometimes', 'array'], 'payout_ids.*' => ['integer']]);
        $query = $batch->payouts()->whereIn('status', [Payout::SCHEDULED, Payout::PROCESSING]);

        if (isset($data['payout_ids'])) {
            $query->whereIn('id', $data['payout_ids']);
        }

        $paid = $query->get()->map(fn (Payout $p) => $payouts->markPaid($p, $request->user()))->count();

        return response()->json(['paid' => $paid, 'batch' => $batch->fresh()]);
    }

    public function failPayout(Request $request, Payout $payout, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            return response()->json(['payout' => $payouts->markFailed($payout, $request->user(), $data['reason'])->toPublicArray()]);
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reversePayout(Request $request, Payout $payout, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            return response()->json(['payout' => $payouts->reverse($payout, $request->user(), $data['reason'])->toPublicArray()]);
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sendTransfers(Request $request, PayoutBatch $batch, PayoutService $payouts): JsonResponse
    {
        try {
            return response()->json($payouts->sendTransfers($batch, $request->user()));
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reconciliation(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return response()->json($reconciliation->report(CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()));
    }

    /**
     * Upload the Paystack transactions/settlement export (CSV with "reference" and "amount" columns).
     */
    public function settlement(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], 'record' => ['sometimes', 'boolean']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::snake(strtolower(trim((string) $h))), (array) fgetcsv($handle, escape: ''));
        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }

        fclose($handle);

        if (! in_array('reference', $header, true) || ! in_array('amount', $header, true)) {
            return response()->json(['message' => 'The file needs "reference" and "amount" columns.'], 422);
        }

        AuditLog::record('money.settlement_compared', $request->user(), $request->user(), ['rows' => count($rows)]);

        return response()->json($reconciliation->compareSettlement($rows, $request->boolean('record')));
    }

    public function issues(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        return response()->json(FinanceIssue::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(50)
            ->through(fn (FinanceIssue $i) => $i->toArray() + ['kind_label' => $i->kindLabel()]));
    }

    public function resolveIssue(Request $request, FinanceIssue $issue, FinanceIssues $issues): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['issue' => $issues->resolve($issue, $request->user(), $data['note'])]);
    }

    public function incentives(): JsonResponse
    {
        return response()->json(['programs' => IncentiveProgram::orderByDesc('id')->get()->map(fn (IncentiveProgram $p) => $p->toPublicArray() + ['budget_minor' => $p->budget_minor, 'awarded_minor' => $p->awarded_minor, 'fleet_id' => $p->fleet_id])]);
    }

    public function storeIncentive(Request $request): JsonResponse
    {
        $data = $this->validateIncentive($request);
        $program = IncentiveProgram::create($data + ['currency' => Money::currency(), 'status' => 'draft', 'created_by' => $request->user()->id]);
        AuditLog::record('money.incentive_created', $request->user(), $program, ['kind' => $program->kind, 'reward_minor' => $program->reward_minor]);

        return response()->json(['program' => $program->toPublicArray()], 201);
    }

    public function updateIncentiveStatus(Request $request, IncentiveProgram $program): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'ended'])]]);

        if ($program->status === 'ended') {
            return response()->json(['message' => 'An ended programme cannot be restarted.'], 422);
        }

        $program->forceFill(['status' => $data['status']])->save();
        AuditLog::record('money.incentive_status', $request->user(), $program, ['status' => $data['status']]);

        return response()->json(['program' => $program->toPublicArray()]);
    }

    public function evaluateIncentive(IncentiveProgram $program, IncentiveEngine $engine): JsonResponse
    {
        return response()->json($engine->evaluate($program));
    }

    public function trialBalance(Request $request, Ledger $ledger): JsonResponse
    {
        return response()->json(['currency' => Money::currency(), 'accounts' => $ledger->trialBalance(Money::currency())->values()]);
    }

    public function ledgerCsv(Request $request): StreamedResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $rows = [['journal_id', 'posted_at', 'kind', 'reason', 'ride_id', 'account', 'debit', 'credit', 'currency']];

        LedgerEntry::query()->with('journal')
            ->whereBetween('created_at', [CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()])
            ->orderBy('id')
            ->each(function (LedgerEntry $e) use (&$rows) {
                $rows[] = [$e->journal_id, $e->journal->posted_at->toIso8601String(), $e->journal->kind, $e->journal->reason, $e->journal->ride_id, $e->account,
                    Money::toDecimalString($e->debit_minor), Money::toDecimalString($e->credit_minor), $e->currency];
            });

        return DriverMoneyController::csv($rows, 'ledger-'.$data['from'].'-'.$data['to'].'.csv');
    }

    public function adjustment(Request $request, Ledger $ledger, PostingRules $rules): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'ride_id' => ['nullable', 'integer', 'exists:rides,id'],
            'lines' => ['required', 'array', 'min:2', 'max:10'],
            'lines.*.account' => ['required', 'string', 'max:80'],
            'lines.*.debit_minor' => ['sometimes', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            foreach ($data['lines'] as $line) {
                Account::parse($line['account']);
            }

            $journal = $ledger->post($rules->adjustment((string) Str::uuid(), Money::currency(), $data['reason'], $data['lines'], $request->user()->id, $data['ride_id'] ?? null));
        } catch (InvalidArgumentException|UnbalancedJournal $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::record('money.ledger_adjustment', $request->user(), $journal, ['reason' => $data['reason']]);

        return response()->json(['journal' => $journal->load('entries')], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateIncentive(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(IncentiveProgram::KINDS)],
            'window' => ['required', Rule::in(IncentiveProgram::WINDOWS)],
            'reward_minor' => ['required', 'integer', 'min:1'],
            'budget_minor' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'rules' => ['required', 'array'],
            'rules.trips' => ['required_if:kind,trip_count,area', 'integer', 'min:1'],
            'rules.lifetime_trips' => ['required_if:kind,milestone', 'integer', 'min:1'],
            'rules.hours' => ['required_if:kind,online_hours', 'numeric', 'min:0.5'],
            'rules.min_acceptance' => ['sometimes', 'numeric', 'between:0,1'],
            'rules.referrals' => ['required_if:kind,referral', 'integer', 'min:1'],
            'rules.polygon' => ['required_if:kind,area', 'array', 'min:3'],
            'rules.polygon.*' => ['array', 'size:2'],
            'rules.polygon.*.*' => ['numeric'],
            'rules.min_rating' => ['sometimes', 'numeric', 'between:0,5'],
        ]);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentArray(Payment $p): array
    {
        return [
            'id' => $p->id, 'ride_id' => $p->ride_id, 'user_id' => $p->user_id, 'purpose' => $p->purpose, 'attempt' => $p->attempt,
            'provider' => $p->provider, 'reference' => $p->reference, 'amount_minor' => $p->amountMinor(), 'refunded_minor' => (int) $p->refunded_minor,
            'currency' => $p->currency, 'status' => $p->status, 'channel' => $p->channel, 'failure_code' => $p->failure_code,
            'failure_message' => $p->failure_message, 'next_retry_at' => $p->next_retry_at?->toIso8601String(),
            'paid_at' => $p->paid_at?->toIso8601String(), 'verified_at' => $p->verified_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundArray(Refund $r): array
    {
        return $r->only(['id', 'payment_id', 'ride_id', 'amount_minor', 'currency', 'reason', 'note', 'charge_back_driver', 'method', 'status', 'failure_message'])
            + ['reason_label' => Refund::REASONS[$r->reason] ?? $r->reason, 'processed_at' => $r->processed_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String()];
    }
}
