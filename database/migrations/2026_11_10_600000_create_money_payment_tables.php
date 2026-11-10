<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-46 money: payment methods (tokenised references only), the ride's chosen method, refunds, raw
     * webhook receipts (idempotency), driver cash opt-in, and additive columns that turn ride_payments
     * into the general payments table. Every existing ride_payments row keeps its meaning: new columns
     * default to the legacy behaviour (purpose ride_fare, attempt 1, gateway = provider).
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // card | business_account
            $table->string('provider', 20)->nullable(); // paystack
            $table->text('authorization_code')->nullable(); // encrypted at rest (cast); the provider's token, never a card number
            $table->string('signature', 120)->nullable(); // provider card fingerprint, for de-duplication
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->string('bank', 80)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->boolean('reusable')->default(false);
            $table->unsignedBigInteger('business_account_id')->nullable(); // seam for the business domain (wave 2)
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active'); // active | removed
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'provider', 'signature']);
        });

        Schema::create('ride_payment_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 20); // card | cash | business_account
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->unsignedBigInteger('business_account_id')->nullable();
            $table->timestamps();
        });

        Schema::table('ride_payments', function (Blueprint $table) {
            $table->string('purpose', 24)->default('ride_fare')->after('provider'); // ride_fare | cancellation_fee | card_setup
            $table->unsignedSmallInteger('attempt')->default(1)->after('purpose');
            $table->string('idempotency_key', 120)->nullable()->unique()->after('attempt');
            $table->foreignId('payment_method_id')->nullable()->after('user_id')->constrained('payment_methods')->nullOnDelete();
            $table->string('channel', 30)->nullable()->after('currency');
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('amount_cents');
            $table->string('failure_code', 40)->nullable()->after('status');
            $table->string('failure_message', 255)->nullable()->after('failure_code');
            $table->timestamp('next_retry_at')->nullable()->after('failure_message');
            $table->timestamp('verified_at')->nullable()->after('paid_at');
            $table->foreignId('confirmed_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();

            $table->index(['purpose', 'status', 'next_retry_at']);
            $table->index(['ride_id', 'purpose', 'attempt']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('ride_payments')->restrictOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason', 40); // see App\Domain\Payments\Models\Refund::REASONS
            $table->string('note', 500)->nullable();
            $table->boolean('charge_back_driver')->default(false);
            $table->string('method', 20); // paystack | manual
            $table->string('status', 16)->default('requested'); // requested | pending | processing | processed | failed
            $table->string('provider_refund_id', 64)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('failure_message', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['method', 'provider_refund_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_key', 64); // sha256 of the event identity (Paystack sends no event id)
            $table->string('event', 60);
            $table->string('reference', 120)->nullable();
            $table->json('payload'); // the signed body minus customer details
            $table->string('status', 16)->default('received'); // received | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('outcome', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('driver_money_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('accepts_cash')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_money_settings');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');

        Schema::table('ride_payments', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'status', 'next_retry_at']);
            $table->dropIndex(['ride_id', 'purpose', 'attempt']);
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn([
                'purpose', 'attempt', 'idempotency_key', 'channel', 'refunded_minor', 'failure_code',
                'failure_message', 'next_retry_at', 'verified_at',
            ]);
        });

        Schema::dropIfExists('ride_payment_choices');
        Schema::dropIfExists('payment_methods');
    }
};
