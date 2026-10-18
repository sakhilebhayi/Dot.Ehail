<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle management: seats, a database-level "one active vehicle per
     * driver" rule, and an optional link from a driver document to the
     * vehicle it belongs to (the existing upload, not new storage).
     *
     * Before the unique index can exist, drivers that already have several
     * active vehicles (nothing used to prevent it) are reduced to the most
     * recently added one. Nothing is deleted.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('seats')->default(4)->after('type');
        });

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('driver_profile_id')->constrained('vehicles')->cascadeOnDelete();
        });

        DB::table('vehicles')
            ->where('is_active', true)
            ->select('driver_profile_id')
            ->groupBy('driver_profile_id')
            ->havingRaw('count(*) > 1')
            ->pluck('driver_profile_id')
            ->each(function ($profileId) {
                $keep = DB::table('vehicles')->where('driver_profile_id', $profileId)->where('is_active', true)->max('id');

                DB::table('vehicles')
                    ->where('driver_profile_id', $profileId)
                    ->where('is_active', true)
                    ->where('id', '!=', $keep)
                    ->update(['is_active' => false]);
            });

        // Partial unique indexes exist on Postgres and SQLite. MySQL has none;
        // there the VehicleManager service is the only guard.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX vehicles_one_active_per_driver ON vehicles (driver_profile_id) WHERE is_active = true');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS vehicles_one_active_per_driver');
        }

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('seats');
        });
    }
};
