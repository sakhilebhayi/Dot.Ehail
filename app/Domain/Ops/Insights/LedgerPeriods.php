<?php

namespace App\Domain\Ops\Insights;

use App\Domain\Ledger\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Revenue, commission and driver earnings by period, read from the double-entry ledger itself (never from fares):
 *   commission revenue = credits - debits on platform_revenue_commission;
 *   fee revenue        = credits - debits on platform_revenue_fees;
 *   driver earnings    = credits - debits on driver_payable and fleet_payable accounts from trip, incentive and
 *                        cancellation-fee journals (payouts are excluded: they move what is owed, they do not earn it);
 *   refunds            = debits - credits on refunds; promotions = debits - credits on promotions_expense.
 * Days are bucketed in SQL (journal posted_at), weeks and months rolled up in PHP. Periods with no journal show 0.
 */
class LedgerPeriods
{
    public const MEASURES = ['commission', 'fees', 'driver_earnings', 'refunds', 'promotions'];

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int>, journals: int} */
    public function revenue(CarbonImmutable $from, CarbonImmutable $to, string $grain, string $currency): array
    {
        $day = Sql::day('j.posted_at');
        $commission = Account::PLATFORM_REVENUE_COMMISSION;
        $fees = Account::PLATFORM_REVENUE_FEES;
        $refunds = Account::REFUNDS;
        $promotions = Account::PROMOTIONS_EXPENSE;

        $rows = DB::table('ledger_entries as e')
            ->join('ledger_journals as j', 'j.id', '=', 'e.journal_id')
            ->where('e.currency', $currency)
            ->whereBetween('j.posted_at', [$from, $to])
            ->selectRaw("{$day} as d")
            ->selectRaw('SUM(CASE WHEN e.account_type = ? THEN e.credit_minor - e.debit_minor ELSE 0 END) as commission', [$commission])
            ->selectRaw('SUM(CASE WHEN e.account_type = ? THEN e.credit_minor - e.debit_minor ELSE 0 END) as fees', [$fees])
            ->selectRaw("SUM(CASE WHEN e.account_type IN ('driver_payable', 'fleet_payable') AND j.kind NOT IN ('payout', 'payout_reversal') THEN e.credit_minor - e.debit_minor ELSE 0 END) as driver_earnings")
            ->selectRaw('SUM(CASE WHEN e.account_type = ? THEN e.debit_minor - e.credit_minor ELSE 0 END) as refunds', [$refunds])
            ->selectRaw('SUM(CASE WHEN e.account_type = ? THEN e.debit_minor - e.credit_minor ELSE 0 END) as promotions', [$promotions])
            ->selectRaw('COUNT(DISTINCT j.id) as journals')
            ->groupBy(DB::raw($day))
            ->get()
            ->keyBy('d');

        $periods = Periods::buckets($from, $to, $grain);
        $out = [];
        $totals = array_fill_keys(self::MEASURES, 0);
        $journals = 0;

        foreach ($periods as $bucket) {
            $row = ['key' => $bucket['key'], 'label' => $bucket['label']] + array_fill_keys(self::MEASURES, 0);
            foreach ($bucket['days'] as $d) {
                $r = $rows->get($d);
                if ($r === null) {
                    continue;
                }
                foreach (self::MEASURES as $m) {
                    $row[$m] += (int) $r->{$m};
                }
                $journals += (int) $r->journals;
            }
            foreach (self::MEASURES as $m) {
                $totals[$m] += $row[$m];
            }
            $out[] = $row;
        }

        return ['rows' => $out, 'totals' => $totals, 'journals' => $journals];
    }
}
