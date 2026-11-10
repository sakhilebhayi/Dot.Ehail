<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 business accounts (docs/R46_BUSINESS.md): accounts, people, departments, ride policies, approvals,
 * business trips (the business side of a ride), charges billed to the account, spend counters for
 * allowances / budgets / credit, monthly statements and payments received.
 * Money is integer minor units with an ISO currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('legal_name', 160);
            $table->string('trading_name', 160)->nullable();
            $table->string('registration_number', 40);
            $table->string('vat_number', 30)->nullable();
            $table->string('billing_email', 190);
            $table->string('address_line1', 160);
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 80);
            $table->string('postal_code', 12)->nullable();
            $table->char('country', 2)->default('ZA');
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 20)->default('pending_review'); // pending_review | active | suspended | declined | closed
            $table->string('status_reason', 40)->nullable(); // overdue | operator | card_failed
            $table->string('status_note', 500)->nullable();
            $table->string('billing_mode', 10)->default('invoice'); // invoice | card
            $table->unsignedBigInteger('credit_limit_minor')->default(0);
            $table->unsignedSmallInteger('terms_days')->default(30);
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->foreignId('card_payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('business_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('cost_centre', 30)->nullable();
            $table->unsignedBigInteger('monthly_budget_minor')->nullable();
            $table->timestamps();

            $table->unique(['business_account_id', 'name']);
        });

        Schema::create('business_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->json('allowed_categories')->nullable(); // null = every category
            $table->json('allowed_days')->nullable(); // ISO weekdays 1..7; null = every day
            $table->unsignedSmallInteger('hours_start')->nullable(); // minutes after local midnight
            $table->unsignedSmallInteger('hours_end')->nullable();
            $table->unsignedBigInteger('max_fare_minor')->nullable();
            $table->json('service_area_ids')->nullable(); // null = anywhere Dot.Ehail operates
            $table->boolean('purpose_required')->default(false);
            $table->unsignedBigInteger('approval_above_minor')->nullable();
            $table->unsignedSmallInteger('approval_window_minutes')->default(30);
            $table->boolean('guest_bookings')->default(false);
            $table->timestamps();

            $table->unique(['business_account_id', 'name']);
        });

        Schema::create('business_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->string('role', 20); // account_admin | travel_manager | finance_viewer | traveller
            $table->string('status', 12)->default('invited'); // invited | active | removed
            $table->foreignId('department_id')->nullable()->constrained('business_departments')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('business_policies')->nullOnDelete();
            $table->unsignedBigInteger('monthly_allowance_minor')->nullable();
            $table->string('employee_ref', 40)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_account_id', 'email']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('business_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_member_id')->constrained('business_members')->cascadeOnDelete();
            $table->foreignId('traveller_member_id')->nullable()->constrained('business_members')->nullOnDelete();
            $table->string('guest_name', 120)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | declined | expired | used | withdrawn
            $table->string('category_code', 40)->nullable();
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address', 255)->nullable();
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->string('dropoff_address', 255)->nullable();
            $table->unsignedBigInteger('estimate_minor');
            $table->char('currency', 3);
            $table->string('purpose', 200)->nullable();
            $table->timestamp('trip_at')->nullable(); // scheduled pickup, or null for "now"
            $table->json('reasons')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamp('decide_by'); // pending requests expire after this
            $table->timestamp('use_by')->nullable(); // approved requests must be booked before this
            $table->unsignedBigInteger('ride_id')->nullable();
            $table->timestamps();

            $table->index(['business_account_id', 'status']);
        });

        Schema::create('business_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booker_member_id')->constrained('business_members')->cascadeOnDelete();
            $table->foreignId('traveller_member_id')->nullable()->constrained('business_members')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('business_departments')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('business_policies')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('business_approvals')->nullOnDelete();
            $table->string('fare_quote_id', 64)->nullable()->unique();
            $table->unsignedBigInteger('ride_id')->nullable()->unique();
            $table->string('status', 12)->default('prepared'); // prepared | booked | charged | released
            $table->string('purpose', 200)->nullable();
            $table->string('rider_name', 120)->nullable(); // guest or the traveller booked for
            $table->text('rider_phone')->nullable(); // encrypted; wiped after retention
            $table->boolean('is_guest')->default(false);
            $table->string('category_code', 40)->nullable();
            $table->string('cost_centre', 30)->nullable(); // snapshot at booking
            $table->string('department_name', 80)->nullable(); // snapshot at booking
            $table->unsignedBigInteger('estimate_minor')->default(0);
            $table->unsignedBigInteger('reserved_minor')->default(0); // held against limits until charged or released
            $table->unsignedBigInteger('charged_minor')->default(0);
            $table->char('currency', 3)->default('ZAR');
            $table->char('spend_month', 7)->nullable(); // YYYY-MM in the account's timezone
            $table->json('policy_snapshot')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('rider_phone_purged_at')->nullable();
            $table->timestamps();

            $table->index(['business_account_id', 'status']);
            $table->index(['business_account_id', 'spend_month']);
        });

        Schema::create('business_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->char('period', 7); // YYYY-MM
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->bigInteger('opening_minor');
            $table->unsignedBigInteger('charges_minor');
            $table->unsignedBigInteger('payments_minor');
            $table->bigInteger('closing_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->char('currency', 3);
            $table->string('billing_mode', 10);
            $table->string('status', 12)->default('issued'); // issued | paid | overdue | nothing_due
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->json('snapshot'); // account details and lines frozen at issue
            $table->timestamps();

            $table->unique(['business_account_id', 'period']);
        });

        Schema::create('business_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_trip_id')->nullable()->constrained('business_trips')->nullOnDelete();
            $table->unsignedBigInteger('ride_id');
            $table->unsignedBigInteger('payment_id')->nullable(); // money's payments row this charge settled
            $table->foreignId('traveller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24); // ride_fare | cancellation_fee
            $table->string('reference', 60)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->char('currency', 3);
            $table->foreignId('statement_id')->nullable()->constrained('business_statements')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['business_account_id', 'recorded_at']);
        });

        Schema::create('business_spend_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 12); // member | department | credit
            $table->unsignedBigInteger('scope_id');
            $table->char('period', 7); // YYYY-MM, or "open" for credit
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'period']);
        });

        Schema::create('business_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statement_id')->nullable()->constrained('business_statements')->nullOnDelete();
            $table->string('method', 10); // eft | card
            $table->string('reference', 80)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 12)->default('pending'); // pending | paid | failed
            $table->string('failure_message', 300)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['business_payments', 'business_spend_counters', 'business_charges', 'business_statements', 'business_trips', 'business_approvals', 'business_members', 'business_policies', 'business_departments', 'business_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
