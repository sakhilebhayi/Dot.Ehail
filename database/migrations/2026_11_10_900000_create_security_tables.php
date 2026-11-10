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
        Schema::dropIfExists('security_known_devices');
    }
};
