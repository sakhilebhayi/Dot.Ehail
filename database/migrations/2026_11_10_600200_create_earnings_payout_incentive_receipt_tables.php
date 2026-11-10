<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Driver earnings (one row per trip, cancellation fee, incentive award or adjustment), payout
     * accounts (bank details encrypted, blind index for duplicate detection), payout batches and
     * payouts, incentive programmes and progress, and immutable sequence-numbered receipts.
     */
    public function up(): void
    {
        Schema::create('driver_earnings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // trip | cancellation_fee | incentive | adjustment
            $table->string('source_key', 120)->unique(); // e.g. trip:41, incentive:3:7:2026-11-09
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete();
            $table->string('payee_type', 12)->default('driver'); // driver | fleet_owner
            $table->foreignId('payee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('fare_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('incentive_minor')->default(0);
            $table->bigInteger('net_minor');
            $table->bigInteger('cash_collected_minor')->default(0);
            $table->bigInteger('estimate_minor')->nullable();
            $table->json('breakdown')->nullable(); // deductions[], estimate vs actual reasons, plan
            $table->string('status', 12)->default('pending'); // pending | available | paid
            $table->timestamp('earned_at');
            $table->timestamp('available_at')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'earned_at']);
            $table->index(['payee_user_id', 'status']);
            $table->index(['fleet_id', 'earned_at']);
        });

        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('payee_type', 12)->default('driver'); // driver | fleet_owner
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete();
            $table->text('account_holder'); // encrypted
            $table->text('account_number'); // encrypted
            $table->string('account_number_index', 64); // keyed blind index (HMAC) for duplicate detection
            $table->string('account_last4', 4);
            $table->string('bank_code', 20);
            $table->string('bank_name', 80);
            $table->string('account_type', 12)->default('personal'); // personal | business
            $table->string('verification_status', 16)->default('unverified'); // unverified | verified | failed | unavailable
            $table->string('verification_message', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('provider_recipient_code', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['account_number_index', 'bank_code']);
        });

        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft | exported | processing | completed | partially_failed | cancelled
            $table->string('method', 20)->default('manual'); // manual | paystack_transfer
            $table->unsignedInteger('payout_count')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->json('summary')->nullable(); // payees carried forward and why (below minimum, no bank account, cash owed)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'period_end']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_batch_id')->constrained('payout_batches')->restrictOnDelete();
            $table->foreignId('payee_user_id')->constrained('users')->restrictOnDelete();
            $table->string('payee_type', 12);
            $table->foreignId('payout_account_id')->nullable()->constrained('payout_accounts')->nullOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('earnings_minor'); // sum of the available earnings included
            $table->bigInteger('cash_offset_minor')->default(0); // cash commission owed, netted
            $table->bigInteger('amount_minor'); // what is sent to the bank
            $table->json('composition'); // earning ids and totals by kind; cash accounts netted
            $table->string('status', 16)->default('scheduled'); // scheduled | processing | paid | failed | reversed
            $table->string('reference', 64)->unique();
            $table->string('provider_transfer_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();

            $table->unique(['payout_batch_id', 'payee_user_id']);
            $table->index(['payee_user_id', 'status']);
        });

        Schema::create('incentive_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 20); // trip_count | online_hours | area | referral | milestone
            $table->json('rules');
            $table->bigInteger('reward_minor');
            $table->string('currency', 3);
            $table->string('window', 12)->default('week'); // day | week | month | program
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 12)->default('draft'); // draft | active | paused | ended
            $table->bigInteger('budget_minor')->nullable();
            $table->bigInteger('awarded_minor')->default(0);
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete(); // limit to one fleet's drivers
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('incentive_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incentive_program_id')->constrained('incentive_programs')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->timestamp('window_end')->nullable();
            $table->json('progress'); // measured facts: trips, hours, acceptance, target
            $table->unsignedInteger('percent')->default(0);
            $table->string('status', 20)->default('in_progress'); // in_progress | earned | not_met | budget_exhausted | unmeasurable
            $table->timestamp('earned_at')->nullable();
            $table->unsignedBigInteger('earning_id')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['incentive_program_id', 'driver_id', 'window_start'], 'incentive_progress_window_unique');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('kind', 20)->default('ride'); // ride | cancellation_fee
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->foreignId('passenger_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('currency', 3);
            $table->bigInteger('total_minor');
            $table->json('snapshot');
            $table->timestamp('issued_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['ride_id', 'kind']);
            $table->index(['passenger_id', 'issued_at']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER receipts_no_update BEFORE UPDATE ON receipts BEGIN SELECT RAISE(ABORT, 'receipts are immutable'); END;");
        } elseif (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION receipts_refuse_update() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'receipts are immutable'; END; \$\$ LANGUAGE plpgsql;");
            DB::unprepared('CREATE TRIGGER receipts_immutable BEFORE UPDATE ON receipts FOR EACH ROW EXECUTE FUNCTION receipts_refuse_update();');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS receipts_immutable ON receipts');
            DB::unprepared('DROP FUNCTION IF EXISTS receipts_refuse_update()');
        }

        Schema::dropIfExists('receipts');
        Schema::dropIfExists('incentive_progress');
        Schema::dropIfExists('incentive_programs');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_batches');
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('driver_earnings');
    }
};
