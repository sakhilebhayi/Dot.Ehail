<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            // New fleets default to pending: a fleet an applicant creates
            // for themselves must be vetted by a platform operator before
            // its owner can review anyone's driver application.
            $table->string('status')->default('pending')->after('owner_user_id');
        });

        // Fleets that already exist predate the vetting step and keep
        // working exactly as before.
        DB::table('fleets')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
