<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment state for a ride's fare. Every existing ride (including every completed one, which
     * completed when no payment existed) is 'not_due': the default does that without touching a row,
     * so this is a metadata-only change on a large table. Only rides completed from now on, while
     * Paystack is configured, become 'unpaid'.
     *
     * The waiver columns record who waived a fare and why (the audit log carries the same facts).
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('payment_status', 16)->default('not_due')->after('final_fare_set_by');
            $table->text('fare_waived_reason')->nullable()->after('payment_status');
            $table->foreignId('fare_waived_by')->nullable()->after('fare_waived_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('fare_waived_at')->nullable()->after('fare_waived_by');

            $table->index(['payment_status', 'completed_at'], 'rides_payment_status_completed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_payment_status_completed_at_index');
            $table->dropConstrainedForeignId('fare_waived_by');
            $table->dropColumn(['payment_status', 'fare_waived_reason', 'fare_waived_at']);
        });
    }
};
