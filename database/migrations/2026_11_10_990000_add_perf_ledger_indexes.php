<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 perf (docs/R46_PERF.md section 5, D-1516): the two ledger columns the insights revenue query and every
 * per-journal check join or range on. PostgreSQL (unlike MySQL) creates no index for a foreign key, so
 * ledger_entries.journal_id had none; ledger_journals.posted_at was indexed only behind kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            if (! Schema::hasIndex('ledger_entries', 'ledger_entries_journal_id_index')) {
                $table->index('journal_id');
            }
        });

        Schema::table('ledger_journals', function (Blueprint $table) {
            if (! Schema::hasIndex('ledger_journals', 'ledger_journals_posted_at_index')) {
                $table->index('posted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropIndex(['journal_id']));
        Schema::table('ledger_journals', fn (Blueprint $table) => $table->dropIndex(['posted_at']));
    }
};
