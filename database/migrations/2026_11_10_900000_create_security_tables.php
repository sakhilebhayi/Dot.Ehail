<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 security: known sign-in devices (keyed hash only) and the payout account cooling-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'device_hash']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Set when an account with history is closed and anonymised (AccountClosure); the row stays as a tombstone.
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('fare_quotes', function (Blueprint $table) {
            // Consumed quotes keep their fare; their exact coordinates are coarsened after the retention period.
            $table->timestamp('coarsened_at')->nullable();
        });

        Schema::table('payout_accounts', function (Blueprint $table) {
            // A changed account receives payouts only from this moment (cooling-off, R-46 security S-15).
            $table->timestamp('payable_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->dropColumn('payable_from');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
        Schema::table('fare_quotes', function (Blueprint $table) {
            $table->dropColumn('coarsened_at');
        });
        Schema::dropIfExists('security_known_devices');
    }
};
