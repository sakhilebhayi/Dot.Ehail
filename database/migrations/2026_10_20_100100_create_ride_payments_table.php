<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per payment attempt, plus one per charge Paystack reports that we cannot match to an
     * attempt (ride_id null, status 'unmatched') so an operator can look at it. Amounts are integer
     * cents. `summary` keeps only a handful of Paystack's fields (see PaystackTransaction::safePayload),
     * never customer or card details.
     *
     * unique(reference) stops a reference being used twice; unique(provider, provider_transaction_id)
     * stops one Paystack transaction being counted for two payments (NULLs are distinct, so open
     * attempts do not collide).
     */
    public function up(): void
    {
        Schema::create('ride_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20)->default('paystack');
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 16)->default('initiated');
            $table->string('provider_transaction_id', 64)->nullable();
            $table->text('authorization_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('summary')->nullable();
            $table->string('unmatched_reason', 40)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['ride_id', 'status']);
            $table->index(['status', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_payments');
    }
};
