<?php

namespace Tests\Feature\Money;

use App\Domain\Ledger\Account;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerJournal;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\Reconciliation;
use App\Domain\Ledger\UnbalancedJournal;
use App\Domain\Payments\Money;
use App\Domain\Payments\Seams\EarningsSplit;
use App\Domain\Payments\Seams\FareBreakdown;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class LedgerInvariantsTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    public function test_an_unbalanced_or_empty_journal_is_refused(): void
    {
        $this->expectException(UnbalancedJournal::class);
        app(Ledger::class)->post((new JournalEntry('t', 'k1', 'ZAR', 'x'))->debit(Account::PLATFORM_BANK, 100)->credit(Account::REFUNDS, 99));
    }

    public function test_negative_amounts_and_unknown_accounts_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JournalEntry('t', 'k', 'ZAR', 'x'))->debit(Account::PLATFORM_BANK, -1);
    }

    public function test_unknown_account_types_and_missing_parties_are_refused(): void
    {
        foreach (['bank_of_mars', 'driver_payable', 'platform_bank:4'] as $bad) {
            try {
                (new JournalEntry('t', 'k', 'ZAR', 'x'))->debit($bad, 1);
                $this->fail("{$bad} accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_posting_the_same_key_twice_writes_once(): void
    {
        $entry = fn () => (new JournalEntry('t', 'same-key', 'ZAR', 'x'))->debit(Account::PLATFORM_BANK, 100)->credit(Account::REFUNDS, 100);

        $a = app(Ledger::class)->post($entry());
        $b = app(Ledger::class)->post($entry());

        $this->assertSame($a->id, $b->id);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_the_database_refuses_edits_and_deletes(): void
    {
        $journal = app(Ledger::class)->post((new JournalEntry('t', 'imm', 'ZAR', 'x'))->debit(Account::PLATFORM_BANK, 100)->credit(Account::REFUNDS, 100));

        foreach ([
            fn () => DB::table('ledger_entries')->update(['debit_minor' => 5]),
            fn () => DB::table('ledger_entries')->delete(),
            fn () => DB::table('ledger_journals')->update(['reason' => 'changed']),
            fn () => DB::table('ledger_entries')->insert(['journal_id' => $journal->id, 'account' => 'refunds', 'account_type' => 'refunds', 'debit_minor' => 5, 'credit_minor' => 5, 'currency' => 'ZAR']),
            fn () => DB::table('ledger_entries')->insert(['journal_id' => $journal->id, 'account' => 'refunds', 'account_type' => 'refunds', 'debit_minor' => 0, 'credit_minor' => 0, 'currency' => 'ZAR']),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('the database accepted a change to the ledger');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $journal->entries()->first()->update(['debit_minor' => 1]);
    }

    public function test_postgresql_refuses_to_commit_an_unbalanced_journal(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The deferred balance trigger exists on PostgreSQL only (SQLite: application check + reconciliation).');
        }

        $journal = app(Ledger::class)->post((new JournalEntry('t', 'pg-balance', 'ZAR', 'x'))->debit(Account::PLATFORM_BANK, 100)->credit(Account::REFUNDS, 100));

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($journal) {
            DB::table('ledger_entries')->insert(['journal_id' => $journal->id, 'account' => 'refunds', 'account_type' => 'refunds', 'debit_minor' => 0, 'credit_minor' => 5, 'currency' => 'ZAR']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    /**
     * Property: for random fares, promotions, taxes, fees, commission rates and settlement paths, every
     * journal the posting rules build balances, and the whole ledger sums to zero.
     */
    public function test_property_every_posting_rule_balances_for_random_inputs(): void
    {
        $passenger = User::factory()->create();
        $rideIds = [];
        for ($r = 1; $r <= 40; $r++) {
            // Real ids (sequences differ per database); completed, so one passenger may hold many (dispatch D-402).
            $rideIds[] = $this->makeRide(['status' => 'completed'], $passenger)->id;
        }

        mt_srand(4646);
        $rules = app(PostingRules::class);
        $ledger = app(Ledger::class);

        for ($i = 1; $i <= 300; $i++) {
            $gross = mt_rand(1, 5_000_000);
            $tax = mt_rand(0, 1) ? intdiv($gross * 15, 115) : 0;
            $fees = mt_rand(0, min(2000, $gross - $tax));
            $promo = mt_rand(0, 3) === 0 ? mt_rand(0, $gross + 500) : 0;
            $base = $gross - $tax - $fees;
            $commission = Money::percentOf($base, mt_rand(0, 4000) / 100);
            $deduction = mt_rand(0, 1) ? mt_rand(0, $base - $commission) : 0;
            $net = $base - $commission - $deduction;

            $fare = new FareBreakdown($gross, 'ZAR', [], $promo, $tax, $fees);
            $split = new EarningsSplit($commission, $base - $commission, $deduction > 0 ? [['code' => 'x', 'label' => 'x', 'amount_minor' => $deduction]] : [], $net, 'p');
            $driver = mt_rand(1, 50);
            $ride = $rideIds[($i - 1) % 40];

            $journals = [
                $rules->tripRevenue($ride, $fare, $split, Account::driverPayable($driver)),
                $rules->paymentSettled($i, $ride, mt_rand(0, 1) ? Account::GATEWAY_CLEARING : Account::cashWithDriver($driver), max(1, $fare->payableMinor()), 'ZAR', 'card'),
                $rules->refund($i, $ride, mt_rand(1, 1000), 'ZAR', Account::GATEWAY_CLEARING, 'r'),
                $rules->cancellationFee($ride, $fee = mt_rand(1, 5000), mt_rand(0, $fee), 'ZAR', Account::driverPayable($driver)),
                $rules->incentiveEarned("p{$i}", mt_rand(1, 100000), 'ZAR', Account::driverPayable($driver), 'Weekly', $i),
            ];

            $earnings = [Account::driverPayable($driver) => $e = mt_rand(1, 100000), Account::fleetPayable(3) => $f = mt_rand(-min(500, $e - 1), 5000)];
            $offset = mt_rand(0, max(0, $e + $f));
            $journals[] = $rules->payoutPaid($i, $earnings, $offset > 0 ? [Account::cashWithDriver($driver) => $offset] : [], $e + $f - $offset, 'ZAR', mt_rand(0, 1) ? 'manual' : 'paystack_transfer');
            $journals[] = $rules->payoutReversed($i, $earnings, $offset > 0 ? [Account::cashWithDriver($driver) => $offset] : [], $e + $f - $offset, 'ZAR', 'manual');

            foreach ($journals as $journal) {
                $this->assertTrue($journal->isBalanced(), "journal {$journal->idempotencyKey} at iteration {$i}");

                if ($i <= 40) {
                    $ledger->post($journal);
                }
            }
        }

        $this->assertSame(0, $ledger->unbalancedJournals()->count());
        $trial = $ledger->trialBalance('ZAR');
        $this->assertSame($trial->sum('debit_minor'), $trial->sum('credit_minor'));
        $this->assertTrue(app(Reconciliation::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay())['ledger']['balanced']);
        $this->assertSame(280, LedgerJournal::count());
    }

    public function test_a_split_that_does_not_add_up_to_the_fare_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PostingRules::class)->tripRevenue(1, new FareBreakdown(10000, 'ZAR', []), new EarningsSplit(2000, 8000, [], 7999, 'p'), Account::driverPayable(1));
    }

    public function test_money_helpers_never_use_floats_for_amounts(): void
    {
        $this->assertSame(12550, Money::decimalToMinor('125.50'));
        $this->assertSame(12550, Money::decimalToMinor(125.5));
        $this->assertSame(12551, Money::decimalToMinor('125.505'));
        $this->assertSame(1, Money::decimalToMinor('0.005'));
        $this->assertSame(-1050, Money::decimalToMinor('-10.50'));
        $this->assertSame(2196, Money::percentOf(12550, 17.5)); // 2196.25
        $this->assertSame(3, Money::percentOf(5, 50)); // 2.5 rounds half up
    }
}
