<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessCharge;
use App\Domain\Business\Models\BusinessPayment;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\Notifications\StatementIssuedNotification;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Models\Ride;
use App\Support\Ui\PlaceNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Monthly statements, built from the ledger so they reconcile by construction:
 *  opening  = business_receivable:{account} balance before the period
 *  charges  = debits to it in the period (each one a payment-settled journal of a business charge)
 *  payments = credits to it in the period (EFTs recorded, company-card collections)
 *  closing  = opening + charges - payments = the balance at the end of the period
 * Lines are the charges whose journals fall in the period, with the trip details frozen at issue.
 * A VAT line appears only when the operator is VAT registered (money config) and the fares carried tax.
 */
class Statements
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Figures for a period straight from the ledger (also used for the month-to-date view).
     *
     * @return array{opening: int, charges: int, payments: int, closing: int, tax: int, lines: list<array<string, mixed>>, credits: list<array<string, mixed>>}
     */
    public function figures(BusinessAccount $account, string $period): array
    {
        [$start, $end] = BusinessClock::monthBounds($period);
        $ledgerAccount = Account::of(Account::BUSINESS_RECEIVABLE, $account->id);

        $opening = (int) LedgerEntry::where('account', $ledgerAccount)->where('currency', $account->currency)->where('created_at', '<', $start)
            ->selectRaw('COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) as b')->value('b');

        $entries = LedgerEntry::with('journal')->where('account', $ledgerAccount)->where('currency', $account->currency)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderBy('id')->get();

        $debits = $entries->where('debit_minor', '>', 0);
        $credits = $entries->where('credit_minor', '>', 0);
        $charges = BusinessCharge::with(['trip.traveller.user', 'trip.booker.user'])->where('business_account_id', $account->id)
            ->whereIn('payment_id', $debits->map(fn (LedgerEntry $e) => $e->journal?->payment_id)->filter()->all())
            ->get()->keyBy('payment_id');
        $rides = Ride::whereIn('id', $charges->pluck('ride_id')->all())->get()->keyBy('id');

        $lines = $debits->map(function (LedgerEntry $entry) use ($charges, $rides) {
            $charge = $charges->get($entry->journal?->payment_id);
            $trip = $charge?->trip;
            $ride = $charge ? $rides->get($charge->ride_id) : null;

            return [
                'date' => $entry->created_at->toIso8601String(),
                'kind' => $charge?->kind ?? 'adjustment',
                'reference' => $charge?->reference ?? $entry->journal?->idempotency_key,
                'ride_id' => $charge?->ride_id,
                'rider' => $trip?->riderLabel() ?? ($charge ? 'Unknown' : null),
                'booked_by' => $trip?->isOnBehalf() ? $trip->booker?->displayName() : null,
                'department' => $trip?->department_name,
                'cost_centre' => $trip?->cost_centre,
                'purpose' => $trip?->purpose,
                'category' => $trip?->category_code,
                // Words, never "Pinned location (lat, lng)" (R-46 polish, D-2201).
                'pickup' => $ride ? PlaceNames::for($ride->pickup_address, $ride->pickup_lat, $ride->pickup_lng) : null,
                'dropoff' => $ride ? PlaceNames::for($ride->dropoff_address, $ride->dropoff_lat, $ride->dropoff_lng) : null,
                'description' => $charge === null ? (string) $entry->journal?->reason : null,
                'amount_minor' => (int) $entry->debit_minor,
                'tax_minor' => (int) ($charge?->tax_minor ?? 0),
            ];
        })->values()->all();

        $creditLines = $credits->map(fn (LedgerEntry $entry) => [
            'date' => $entry->created_at->toIso8601String(),
            'reference' => $entry->journal?->idempotency_key,
            'description' => (string) $entry->journal?->reason,
            'amount_minor' => (int) $entry->credit_minor,
        ])->values()->all();

        $chargesTotal = (int) $debits->sum('debit_minor');
        $paymentsTotal = (int) $credits->sum('credit_minor');

        return [
            'opening' => $opening,
            'charges' => $chargesTotal,
            'payments' => $paymentsTotal,
            'closing' => $opening + $chargesTotal - $paymentsTotal,
            'tax' => (int) array_sum(array_column($lines, 'tax_minor')),
            'lines' => $lines,
            'credits' => $creditLines,
        ];
    }

    /**
     * Issue the statement for a closed month (idempotent: one per account and period).
     */
    public function issue(BusinessAccount $account, string $period, bool $notify = true): BusinessStatement
    {
        $existing = BusinessStatement::where('business_account_id', $account->id)->where('period', $period)->first();

        if ($existing !== null) {
            return $existing;
        }

        [$start, $end] = BusinessClock::monthBounds($period);

        if ($end->isFuture()) {
            throw BusinessRefusal::one('period_open', 'A statement can be issued only after the month has ended.', 409);
        }

        $figures = $this->figures($account, $period);
        $vatShown = (bool) config('money.vat.registered') && filled(config('money.vat.number')) && $figures['tax'] > 0;
        $issuedAt = now();
        $dueAt = $account->billing_mode === BusinessAccount::CARD ? $issuedAt : CarbonImmutable::instance($issuedAt)->addDays((int) $account->terms_days);

        try {
            $statement = DB::transaction(function () use ($account, $period, $start, $end, $figures, $vatShown, $issuedAt, $dueAt) {
                $statement = BusinessStatement::create([
                    'business_account_id' => $account->id,
                    'number' => sprintf('%s-%s-%05d', config('ehail_business.statement_prefix', 'EHB'), str_replace('-', '', $period), $account->id),
                    'period' => $period,
                    'period_start' => $start,
                    'period_end' => $end,
                    'opening_minor' => $figures['opening'],
                    'charges_minor' => $figures['charges'],
                    'payments_minor' => $figures['payments'],
                    'closing_minor' => $figures['closing'],
                    'tax_minor' => $vatShown ? $figures['tax'] : 0,
                    'currency' => $account->currency,
                    'billing_mode' => $account->billing_mode,
                    'status' => $figures['closing'] > 0 ? BusinessStatement::ISSUED : BusinessStatement::NOTHING_DUE,
                    'issued_at' => $issuedAt,
                    'due_at' => $dueAt,
                    'snapshot' => [
                        'period_label' => BusinessClock::monthLabel($period),
                        'account' => [
                            'legal_name' => $account->legal_name,
                            'trading_name' => $account->trading_name,
                            'registration_number' => $account->registration_number,
                            'vat_number' => $account->vat_number,
                            'billing_email' => $account->billing_email,
                            'address' => array_values(array_filter([$account->address_line1, $account->address_line2, $account->city, $account->postal_code, config("ehail_business.countries.{$account->country}.name", $account->country)])),
                        ],
                        'operator' => [
                            'name' => (string) config('money.receipts.operator_name'),
                            'vat_number' => $vatShown ? config('money.vat.number') : null,
                            'support_email' => config('money.receipts.support_email'),
                        ],
                        'vat_shown' => $vatShown,
                        'vat_rate_percent' => $vatShown ? config('money.vat.rate_percent') : null,
                        'terms_days' => $account->terms_days,
                        'lines' => $figures['lines'],
                        'credits' => $figures['credits'],
                    ],
                ]);

                BusinessCharge::where('business_account_id', $account->id)->whereNull('statement_id')
                    ->whereIn('reference', array_filter(array_column($figures['lines'], 'reference')))
                    ->update(['statement_id' => $statement->id]);
                BusinessPayment::where('business_account_id', $account->id)->whereNull('statement_id')->where('status', BusinessPayment::PAID)
                    ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->update(['statement_id' => $statement->id]);

                return $statement;
            });
        } catch (UniqueConstraintViolationException) {
            return BusinessStatement::where('business_account_id', $account->id)->where('period', $period)->firstOrFail();
        }

        if ($notify && $statement->status === BusinessStatement::ISSUED) {
            Notification::route('mail', $account->billing_email)->notify(new StatementIssuedNotification($statement));
        }

        return $statement;
    }

    /**
     * Amount still owed on a statement: its closing balance less everything paid since its period
     * ended (payments settle the oldest statement first).
     */
    public function owed(BusinessStatement $statement): int
    {
        if ($statement->closing_minor <= 0) {
            return 0;
        }

        $paidSince = (int) LedgerEntry::where('account', Account::of(Account::BUSINESS_RECEIVABLE, $statement->business_account_id))
            ->where('currency', $statement->currency)->where('created_at', '>=', $statement->period_end)->sum('credit_minor');

        return max(0, $statement->closing_minor - $paidSince);
    }

    /**
     * CSV rows (formula-neutralised) for a statement or a month-to-date view.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function csv(array $lines, string $currency): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Reference', 'Type', 'Ride', 'Rider', 'Booked by', 'Department', 'Cost centre', 'Purpose', 'Category', 'From', 'To', 'Amount', 'Tax included', 'Currency'], escape: '\\');

        foreach ($lines as $line) {
            fputcsv($out, array_map([$this, 'neutralise'], [
                BusinessClock::local(CarbonImmutable::parse($line['date']))->format('Y-m-d H:i'),
                $line['reference'] ?? '',
                $line['kind'] ?? '',
                $line['ride_id'] ?? '',
                $line['rider'] ?? '',
                $line['booked_by'] ?? '',
                $line['department'] ?? '',
                $line['cost_centre'] ?? '',
                $line['purpose'] ?? '',
                $line['category'] ?? '',
                $line['pickup'] ?? '',
                $line['dropoff'] ?? '',
                number_format(((int) $line['amount_minor']) / 100, 2, '.', ''),
                number_format(((int) ($line['tax_minor'] ?? 0)) / 100, 2, '.', ''),
                $currency,
            ]), escape: '\\');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Accounts that should have a statement for this period (active or suspended, opened before it ended).
     *
     * @return Collection<int, BusinessAccount>
     */
    public function accountsDue(string $period): Collection
    {
        [, $end] = BusinessClock::monthBounds($period);

        return BusinessAccount::whereIn('status', [BusinessAccount::ACTIVE, BusinessAccount::SUSPENDED])
            ->where('reviewed_at', '<', $end)
            ->whereDoesntHave('statements', fn ($q) => $q->where('period', $period))
            ->get();
    }

    private function neutralise(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
