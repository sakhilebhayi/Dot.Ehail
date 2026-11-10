<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Double-entry ledger. A journal is one business event; its entries are the debit and credit lines.
     * Both tables are append-only: the application never updates or deletes them (model guards), and
     * database triggers refuse UPDATE and DELETE on SQLite and PostgreSQL (MySQL: attempted, logged if
     * the host refuses trigger privileges). Each entry is one-sided and non-negative (trigger on insert).
     * On PostgreSQL a deferred constraint trigger also refuses to COMMIT a journal whose debits and
     * credits differ. Everywhere, App\Domain\Ledger\Ledger::post refuses an unbalanced journal before
     * writing, and the reconciliation report re-checks every journal.
     *
     * finance_issues is the operations exceptions list; money_sequences issues gapless receipt numbers.
     */
    public function up(): void
    {
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('idempotency_key', 160)->unique();
            $table->string('currency', 3);
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('refund_id')->nullable()->index();
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->unsignedBigInteger('earning_id')->nullable()->index();
            $table->string('reason', 500);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'posted_at']);
            $table->index('ride_id');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->string('account', 80); // e.g. driver_payable:12
            $table->string('account_type', 40); // e.g. driver_payable
            $table->unsignedBigInteger('party_id')->nullable(); // the id after the colon
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->string('currency', 3);
            $table->timestamp('created_at')->nullable();

            $table->index(['account', 'currency']);
            $table->index(['account_type', 'party_id']);
        });

        Schema::create('finance_issues', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('severity', 10)->default('warning'); // info | warning | critical
            $table->string('dedupe_key', 160)->unique();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
        });

        Schema::create('money_sequences', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        $this->installGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('money_sequences');
        Schema::dropIfExists('finance_issues');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_journals');
    }

    private function installGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (['ledger_journals', 'ledger_entries'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'ledger rows are immutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'ledger rows are immutable'); END;");
            }

            DB::unprepared("CREATE TRIGGER ledger_entries_one_sided BEFORE INSERT ON ledger_entries
                WHEN NOT ((NEW.debit_minor > 0 AND NEW.credit_minor = 0) OR (NEW.credit_minor > 0 AND NEW.debit_minor = 0))
                BEGIN SELECT RAISE(ABORT, 'a ledger entry is exactly one positive debit or credit'); END;");

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION ledger_refuse_change() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'ledger rows are immutable'; END; \$\$ LANGUAGE plpgsql;");

            foreach (['ledger_journals', 'ledger_entries'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ledger_refuse_change();");
            }

            DB::unprepared('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_sided CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');

            DB::unprepared("CREATE OR REPLACE FUNCTION ledger_journal_balanced() RETURNS trigger AS \$\$
                DECLARE diff numeric;
                BEGIN
                    SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) INTO diff FROM ledger_entries WHERE journal_id = NEW.journal_id;
                    IF diff <> 0 THEN RAISE EXCEPTION 'ledger journal % does not balance', NEW.journal_id; END IF;
                    RETURN NULL;
                END; \$\$ LANGUAGE plpgsql;");

            DB::unprepared('CREATE CONSTRAINT TRIGGER ledger_entries_balanced AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_journal_balanced();');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                foreach (['ledger_journals', 'ledger_entries'] as $table) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger rows are immutable'");
                    DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger rows are immutable'");
                }
            } catch (Throwable $e) {
                Log::warning('ledger.triggers_not_installed', ['driver' => $driver, 'error' => class_basename($e)]);
            }
        }
    }

    private function dropGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_immutable ON ledger_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_journals_immutable ON ledger_journals');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_journal_balanced()');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_refuse_change()');

            return;
        }

        foreach (['ledger_journals_no_update', 'ledger_journals_no_delete', 'ledger_entries_no_update', 'ledger_entries_no_delete', 'ledger_entries_one_sided'] as $trigger) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            } catch (Throwable) {
                // Not installed on this driver.
            }
        }
    }
};
