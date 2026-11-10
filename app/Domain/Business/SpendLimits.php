<?php

namespace App\Domain\Business;

use App\Domain\Business\Data\TripRequest;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessSpendCounter;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Allowances (per traveller per month), department budgets (per month) and the account's credit limit.
 *
 * A booking reserves its estimated fare against every limit that applies with one conditional UPDATE
 * per counter ("add only if the total stays within the limit"), which is atomic on SQLite, PostgreSQL
 * and MySQL alike: two concurrent bookings can never both take the last of a budget. When the ride is
 * charged the reservation becomes spend (the real fare); when it ends without a charge it is released.
 * Credit: outstanding receivable (the ledger) + reservations + this ride must stay within the limit.
 */
class SpendLimits
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Read-only preview for the policy check (no reservation).
     *
     * @return list<array{code: string, message: string}>
     */
    public function preview(TripRequest $trip): array
    {
        $reasons = [];
        $month = BusinessClock::monthKey($trip->at);
        $money = fn (int $minor) => MoneyFormatter::format($minor, $trip->currency);

        $allowance = $trip->traveller->monthly_allowance_minor;

        if ($allowance !== null) {
            $left = $allowance - $this->used(BusinessSpendCounter::MEMBER, $trip->traveller->id, $month);

            if ($trip->fareMinor > $left) {
                $reasons[] = ['code' => 'allowance_exceeded', 'message' => 'This fare ('.$money($trip->fareMinor).') is more than the '.$money(max(0, $left)).' left of the monthly ride allowance.'];
            }
        }

        $budget = $trip->department?->monthly_budget_minor;

        if ($budget !== null) {
            $left = $budget - $this->used(BusinessSpendCounter::DEPARTMENT, $trip->department->id, $month);

            if ($trip->fareMinor > $left) {
                $reasons[] = ['code' => 'budget_exceeded', 'message' => "The {$trip->department->name} department's ride budget for this month does not cover this fare."];
            }
        }

        if ($trip->fareMinor > $this->creditAvailable($trip->account)) {
            $reasons[] = ['code' => 'credit_limit', 'message' => "The company's credit limit with Dot.Ehail is reached. An account admin can settle the balance or ask for a higher limit."];
        }

        return $reasons;
    }

    /**
     * Reserve the trip's estimate against every limit. Must run inside a transaction (the ride request
     * transaction): a refusal throws and everything rolls back together.
     *
     * @throws BusinessRefusal
     */
    public function reserve(BusinessTrip $trip, BusinessAccount $account, BusinessMember $traveller, int $amount): void
    {
        $month = (string) $trip->spend_month;

        if ($traveller->monthly_allowance_minor !== null
            && ! $this->tryReserve($account->id, BusinessSpendCounter::MEMBER, $traveller->id, $month, $amount, (int) $traveller->monthly_allowance_minor)) {
            throw BusinessRefusal::one('allowance_exceeded', 'The monthly ride allowance does not cover this fare.');
        }

        $department = $trip->department;

        if ($department?->monthly_budget_minor !== null
            && ! $this->tryReserve($account->id, BusinessSpendCounter::DEPARTMENT, $department->id, $month, $amount, (int) $department->monthly_budget_minor)) {
            throw BusinessRefusal::one('budget_exceeded', "The {$department->name} department's ride budget for this month does not cover this fare.");
        }

        $headroom = (int) $account->credit_limit_minor - $this->outstanding($account);

        if (! $this->tryReserve($account->id, BusinessSpendCounter::CREDIT, $account->id, 'open', $amount, $headroom, countSpent: false)) {
            throw BusinessRefusal::one('credit_limit', "The company's credit limit with Dot.Ehail is reached. An account admin can settle the balance or ask for a higher limit.");
        }

        $trip->forceFill(['reserved_minor' => $amount])->save();
    }

    /**
     * The trip was charged $amount: its reservation (if any) turns into spend of the real amount.
     */
    public function commit(BusinessTrip $trip, int $amount): void
    {
        DB::transaction(function () use ($trip, $amount) {
            $locked = BusinessTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $reserved = (int) $locked->reserved_minor;
            $this->moveCounters($locked, -$reserved, $amount);
            $locked->forceFill(['reserved_minor' => 0])->save();
        });
    }

    /** The ride ended without a charge: give the reservation back. */
    public function release(BusinessTrip $trip): void
    {
        DB::transaction(function () use ($trip) {
            $locked = BusinessTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $reserved = (int) $locked->reserved_minor;

            if ($reserved > 0) {
                $this->moveCounters($locked, -$reserved, 0);
            }

            $locked->forceFill(['reserved_minor' => 0])->save();
        });
    }

    public function used(string $scope, int $scopeId, string $period): int
    {
        $row = BusinessSpendCounter::where('scope', $scope)->where('scope_id', $scopeId)->where('period', $period)->first();

        return $row === null ? 0 : (int) $row->reserved_minor + (int) $row->spent_minor;
    }

    public function spent(string $scope, int $scopeId, string $period): int
    {
        return (int) BusinessSpendCounter::where('scope', $scope)->where('scope_id', $scopeId)->where('period', $period)->value('spent_minor');
    }

    /** What the business owes now, from the ledger (business_receivable:{account}). */
    public function outstanding(BusinessAccount $account): int
    {
        return $this->ledger->balance(Account::of(Account::BUSINESS_RECEIVABLE, $account->id), $account->currency);
    }

    public function creditAvailable(BusinessAccount $account): int
    {
        $reserved = (int) BusinessSpendCounter::where('scope', BusinessSpendCounter::CREDIT)->where('scope_id', $account->id)->where('period', 'open')->value('reserved_minor');

        return (int) $account->credit_limit_minor - $this->outstanding($account) - $reserved;
    }

    private function moveCounters(BusinessTrip $trip, int $reservedDelta, int $spentDelta): void
    {
        $month = (string) $trip->spend_month;
        $targets = [[BusinessSpendCounter::MEMBER, $trip->travellerMemberId(), $month, true], [BusinessSpendCounter::CREDIT, (int) $trip->business_account_id, 'open', false]];

        if ($trip->department_id !== null) {
            $targets[] = [BusinessSpendCounter::DEPARTMENT, (int) $trip->department_id, $month, true];
        }

        foreach ($targets as [$scope, $scopeId, $period, $countsSpend]) {
            $counter = $this->counter((int) $trip->business_account_id, $scope, $scopeId, $period);
            $updates = [];

            if ($reservedDelta !== 0) {
                // Never below zero, even if a counter was touched by hand.
                $updates['reserved_minor'] = DB::raw($reservedDelta > 0 ? 'reserved_minor + '.$reservedDelta : 'CASE WHEN reserved_minor > '.(-$reservedDelta).' THEN reserved_minor - '.(-$reservedDelta).' ELSE 0 END');
            }

            if ($countsSpend && $spentDelta > 0) {
                $updates['spent_minor'] = DB::raw('spent_minor + '.$spentDelta);
            }

            if ($updates !== []) {
                BusinessSpendCounter::whereKey($counter->id)->update($updates + ['updated_at' => now()]);
            }
        }
    }

    private function tryReserve(int $accountId, string $scope, int $scopeId, string $period, int $amount, int $limit, bool $countSpent = true): bool
    {
        $counter = $this->counter($accountId, $scope, $scopeId, $period);
        $used = $countSpent ? 'spent_minor + reserved_minor' : 'reserved_minor';

        return BusinessSpendCounter::whereKey($counter->id)
            ->whereRaw("{$used} + ? <= ?", [$amount, $limit])
            ->update(['reserved_minor' => DB::raw('reserved_minor + '.(int) $amount), 'updated_at' => now()]) === 1;
    }

    private function counter(int $accountId, string $scope, int $scopeId, string $period): BusinessSpendCounter
    {
        $existing = BusinessSpendCounter::where('scope', $scope)->where('scope_id', $scopeId)->where('period', $period)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: a lost race on the unique key rolls back only this insert (PostgreSQL would
            // otherwise abort the caller's transaction).
            return DB::transaction(fn () => BusinessSpendCounter::create([
                'business_account_id' => $accountId, 'scope' => $scope, 'scope_id' => $scopeId, 'period' => $period,
            ]));
        } catch (UniqueConstraintViolationException) {
            return BusinessSpendCounter::where('scope', $scope)->where('scope_id', $scopeId)->where('period', $period)->firstOrFail();
        }
    }
}
