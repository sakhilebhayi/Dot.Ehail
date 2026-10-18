<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ride lifecycle integrity: two new terminal/hold states (expired,
     * needs_review), cancellation detail, the reassignment counter, the
     * operator fare override record, and the indexes the queue/dashboard
     * queries need.
     *
     * rides.status was created as an enum, which Postgres/SQLite enforce with
     * a CHECK constraint that rejects the new states -- same trap the
     * driver_profiles.status migration already worked around. It becomes a
     * plain string; the legal values now live in App\Enums\RideStatus.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rides DROP CONSTRAINT IF EXISTS rides_status_check');
        }

        Schema::table('rides', function (Blueprint $table) {
            $table->string('status')->default('requested')->change();
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 40)->nullable()->after('cancelled_by');
            $table->string('cancel_note', 500)->nullable()->after('cancel_reason');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->timestamp('expired_at')->nullable()->after('cancelled_at');
            $table->timestamp('needs_review_at')->nullable()->after('expired_at');
            $table->timestamp('started_at')->nullable()->after('accepted_at');
            $table->unsignedSmallInteger('reassignment_count')->default(0)->after('distance_km');
            $table->text('final_fare_reason')->nullable()->after('final_fare');
            $table->foreignId('final_fare_set_by')->nullable()->after('final_fare_reason')->constrained('users')->nullOnDelete();
        });

        // A ride's waiting window is measured from requested_at; rows that
        // predate that being set reliably fall back to when they were created.
        DB::table('rides')->whereNull('requested_at')->update(['requested_at' => DB::raw('created_at')]);

        Schema::table('rides', function (Blueprint $table) {
            $table->index(['status', 'requested_at'], 'rides_status_requested_at_index');
            $table->index(['driver_id', 'status'], 'rides_driver_id_status_index');
            $table->index(['passenger_id', 'status'], 'rides_passenger_id_status_index');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->index(['fleet_id', 'status'], 'driver_profiles_fleet_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex('driver_profiles_fleet_id_status_index');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_status_requested_at_index');
            $table->dropIndex('rides_driver_id_status_index');
            $table->dropIndex('rides_passenger_id_status_index');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('final_fare_set_by');
            $table->dropColumn([
                'cancel_reason', 'cancel_note', 'cancelled_at', 'expired_at', 'needs_review_at',
                'started_at', 'reassignment_count', 'final_fare_reason',
            ]);
        });
    }
};
