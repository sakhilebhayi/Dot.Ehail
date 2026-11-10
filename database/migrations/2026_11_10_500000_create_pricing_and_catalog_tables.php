<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 pricing and ride-category catalogue (docs/R46_PRICING.md). Every amount is an integer in minor units with an
 * ISO currency beside it. JSON columns are only ever read in PHP (never queried in SQL) so the schema behaves the
 * same on SQLite, MySQL and PostgreSQL.
 *
 * service_area_id has no foreign key on purpose: service_areas belongs to the geo domain and is created by a later
 * migration; ids are checked in the application when a rule set is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            // ride | parcel. Parcel/courier exists in the catalogue but stays inactive until that service is built.
            $table->string('service_kind', 16)->default('ride');
            $table->unsignedTinyInteger('capacity');
            $table->unsignedTinyInteger('luggage')->default(0);
            // Driver rating floor in hundredths (450 = 4.50); null means no floor.
            $table->unsignedSmallInteger('driver_rating_floor')->nullable();
            $table->json('required_documents')->nullable();
            $table->boolean('fleet_only')->default(false);
            // {service_area_ids: int[]|null, windows: [{days: int[], start: "HH:MM", end: "HH:MM"}]|null}
            $table->json('availability')->nullable();
            // {cancellation_free_minutes, max_wait_minutes, pin_required, notes}
            $table->json('policies')->nullable();
            // The value of the old rides.vehicle_type enum this category continues, for history and the adapter.
            $table->string('legacy_vehicle_type', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->string('icon_key', 32)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });

        Schema::create('category_vehicle_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_category_id')->constrained('ride_categories')->cascadeOnDelete();
            // Vehicle classes (vehicles.type) that satisfy this rule.
            $table->json('vehicle_types');
            $table->unsignedSmallInteger('min_year')->nullable();
            $table->unsignedTinyInteger('max_age_years')->nullable();
            $table->unsignedTinyInteger('min_doors')->nullable();
            $table->unsignedTinyInteger('min_seats')->nullable();
            // Accessibility or equipment features the vehicle must declare (e.g. wheelchair_ramp).
            $table->json('required_features')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_rule_sets', function (Blueprint $table) {
            $table->id();
            // All versions of one tariff share a lineage key; (lineage_key, version) is unique.
            $table->string('lineage_key', 26);
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->text('notes')->nullable();
            // Example tariffs ship with the code for development and must be reviewed before launch.
            $table->boolean('is_example')->default(false);
            // draft | published | retired. Published and retired rows are immutable (model guard).
            $table->string('status', 16)->default('draft');
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('service_area_id')->nullable();
            // Pickup-zone constraint (geo zones.slug); null = anywhere in the area.
            $table->string('zone_slug', 64)->nullable();
            $table->foreignId('ride_category_id')->nullable()->constrained('ride_categories')->restrictOnDelete();
            // [{days: [1..7 ISO], start: "HH:MM", end: "HH:MM"}] in `timezone`; null = all the time.
            $table->json('time_windows')->nullable();
            $table->string('timezone', 64);

            $table->unsignedBigInteger('base_minor')->default(0);
            // Per 1 000 m of billable road distance.
            $table->unsignedBigInteger('per_km_minor')->default(0);
            $table->unsignedBigInteger('per_minute_minor')->default(0);
            $table->unsignedBigInteger('minimum_minor')->default(0);
            $table->unsignedBigInteger('booking_fee_minor')->default(0);
            $table->unsignedSmallInteger('wait_free_minutes')->default(0);
            $table->unsignedBigInteger('wait_per_minute_minor')->default(0);
            $table->unsignedSmallInteger('cancel_free_minutes')->default(0);
            $table->unsignedBigInteger('cancel_fee_minor')->default(0);
            // Charged when the driver arrived, waited the free time and the passenger cancelled or did not come.
            $table->unsignedBigInteger('no_show_fee_minor')->default(0);
            // {"<zones.fee_code>": {"pickup_minor": int, "dropoff_minor": int, "label": string}}
            $table->json('zone_fees')->nullable();
            // none | pass_through
            $table->string('toll_mode', 24)->default('none');
            $table->boolean('demand_enabled')->default(false);
            // Highest multiplier allowed, in basis points (10 000 = 1.0x = no uplift).
            $table->unsignedInteger('demand_cap_bp')->default(10000);
            // none | inclusive | exclusive
            $table->string('tax_mode', 16)->default('none');
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->string('tax_label', 32)->nullable();
            $table->unsignedInteger('rounding_unit_minor')->default(1);
            $table->string('rounding_mode', 16)->default('half_up');
            // Finalisation: the quoted fare stands unless the trip exceeds these tolerances (basis points).
            $table->unsignedInteger('distance_tolerance_bp')->default(1500);
            $table->unsignedInteger('duration_tolerance_bp')->default(2500);
            // Without a passenger-requested change, a recalculated fare may rise at most this much over the quote.
            $table->unsignedInteger('overrun_cap_bp')->default(2000);

            $table->timestamp('effective_from')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lineage_key', 'version']);
            $table->index(['status', 'country', 'currency']);
            $table->index(['service_area_id', 'ride_category_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            // Null for automatic promotions; unique when present.
            $table->string('code', 40)->nullable()->unique();
            $table->string('name', 120);
            // code | automatic
            $table->string('kind', 16)->default('code');
            // percent | fixed
            $table->string('discount_type', 16);
            $table->unsignedInteger('percent_bp')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedBigInteger('max_discount_minor')->nullable();
            $table->char('currency', 3);
            // {first_ride_only, service_area_ids, category_keys, time_windows, timezone, min_fare_minor}
            $table->json('eligibility')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });

        Schema::create('fare_quotes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('passenger_id')->nullable()->constrained('users')->nullOnDelete();
            // booking | legacy_request
            $table->string('purpose', 16)->default('booking');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->unsignedBigInteger('service_area_id')->nullable();
            $table->foreignId('ride_category_id')->constrained('ride_categories')->restrictOnDelete();
            $table->foreignId('rule_set_id')->constrained('pricing_rule_sets')->restrictOnDelete();
            $table->unsignedInteger('rule_version');
            $table->unsignedInteger('route_distance_m');
            $table->unsignedInteger('route_duration_s');
            $table->string('route_provider', 32);
            $table->char('polyline_hash', 64);
            $table->json('zone_codes')->nullable();
            $table->json('components');
            $table->unsignedInteger('demand_multiplier_bp')->default(10000);
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->unsignedBigInteger('promotion_discount_minor')->default(0);
            $table->boolean('is_example')->default(false);
            $table->timestamp('expires_at');
            $table->char('signature', 64);
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('ride_id')->nullable()->unique()->constrained('rides')->nullOnDelete();
            $table->timestamps();

            $table->index(['passenger_id', 'created_at']);
            $table->index(['consumed_at', 'expires_at']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fare_quote_id', 26)->nullable();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->unsignedBigInteger('discount_minor');
            $table->char('currency', 3);
            // applied | reversed
            $table->string('status', 16)->default('applied');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'ride_id']);
            $table->index(['promotion_id', 'user_id', 'status']);
        });

        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('lineage_key', 26);
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->boolean('is_example')->default(false);
            $table->string('status', 16)->default('draft');
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('service_area_id')->nullable();
            $table->foreignId('ride_category_id')->nullable()->constrained('ride_categories')->restrictOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete();
            $table->unsignedInteger('percent_bp')->default(0);
            $table->unsignedBigInteger('fixed_minor')->default(0);
            $table->unsignedBigInteger('min_commission_minor')->nullable();
            $table->unsignedBigInteger('max_commission_minor')->nullable();
            // Fare component codes commission is charged on; null = the default set (docs/R46_PRICING.md).
            $table->json('commissionable_codes')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lineage_key', 'version']);
            $table->index(['status', 'country', 'currency']);
        });

        Schema::create('ride_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->unique()->constrained('rides')->cascadeOnDelete();
            $table->string('fare_quote_id', 26)->nullable()->index();
            $table->foreignId('rule_set_id')->nullable()->constrained('pricing_rule_sets')->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->foreignId('ride_category_id')->nullable()->constrained('ride_categories')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedInteger('quoted_distance_m')->nullable();
            $table->unsignedInteger('quoted_duration_s')->nullable();
            $table->unsignedInteger('demand_multiplier_bp')->default(10000);
            $table->json('zone_codes')->nullable();
            $table->unsignedBigInteger('promotion_discount_minor')->default(0);
            $table->foreignId('promotion_redemption_id')->nullable()->constrained('promotion_redemptions')->nullOnDelete();
            $table->json('estimated_components');
            $table->bigInteger('estimated_total_minor');
            $table->json('final_components')->nullable();
            $table->bigInteger('final_total_minor')->nullable();
            // quoted_fare_stands | recalculated | operator_adjusted | cancellation_fee
            $table->string('basis', 32)->nullable();
            // [{code, text, amount_minor, actor_id, at}]
            $table->json('adjustments')->nullable();
            $table->unsignedInteger('actual_distance_m')->nullable();
            $table->unsignedInteger('actual_duration_s')->nullable();
            $table->unsignedInteger('waiting_seconds')->nullable();
            $table->string('distance_method', 32)->nullable();
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('commission_plan_id')->nullable()->constrained('commission_plans')->nullOnDelete();
            $table->json('earnings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_fares');
        Schema::dropIfExists('commission_plans');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('fare_quotes');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('pricing_rule_sets');
        Schema::dropIfExists('category_vehicle_rules');
        Schema::dropIfExists('ride_categories');
    }
};
