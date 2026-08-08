<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable()->after('user_id')->constrained('fleets')->cascadeOnDelete();
            $table->text('rejected_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        // fleet_id starts nullable so this migration can run against any
        // pre-existing rows without failing; there are none in this
        // codebase today (nothing creates a DriverProfile yet -- see this
        // change's spec), so backfilling isn't needed, but the column is
        // tightened to NOT NULL immediately after for any future row.
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable(false)->change();
        });

        // The status column was created as a native enum/CHECK-constrained
        // column. Postgres needs its named CHECK constraint dropped before
        // Schema::change() can widen the column to a plain string; SQLite
        // (used by this app's test suite) has no such named constraint --
        // Schema::change() there rebuilds the table wholesale instead, so
        // the DROP CONSTRAINT statement is a no-op it doesn't understand.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE driver_profiles DROP CONSTRAINT IF EXISTS driver_profiles_status_check');
        }
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
        DB::statement("UPDATE driver_profiles SET status = 'pending' WHERE status NOT IN ('pending', 'approved', 'suspended', 'rejected')");
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['rejected_reason', 'reviewed_at']);
        });
    }
};
