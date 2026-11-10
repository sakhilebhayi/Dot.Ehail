<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-46 dispatch (docs/R46_DISPATCH.md): additive columns on rides plus the
     * dispatch tables. Nothing existing is dropped or rewritten here; the
     * status rename is the separate data migration 2026_11_10_400100.
     *
     * Concurrency guards that work on SQLite, PostgreSQL and MySQL alike use
     * the "nullable unique column" pattern: every engine allows any number of
     * NULLs in a unique index, so a column that holds a value only while a
     * condition is true behaves like a partial unique index everywhere:
     *   rides.active_driver_id        a driver holds at most one active ride
     *   rides.active_passenger_id     a passenger has at most one active ride
     *   driver_availability.current_ride_id   a ride is held by at most one driver
     *   ride_offers.pending_ride_id   a ride has at most one offer in flight
     *   ride_offers.pending_driver_id a driver sees at most one offer at a time
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            // Feed version: epoch microseconds of the last change, strictly increasing per ride (FeedVersion).
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('active_driver_id')->nullable()->unique('rides_active_driver_unique');
            $table->unsignedBigInteger('active_passenger_id')->nullable()->unique('rides_active_passenger_unique');

            // Quote lock (pricing seam) and money in minor units.
            $table->string('fare_quote_id', 64)->nullable()->index();
            $table->json('quote_snapshot')->nullable();
            $table->string('category_code', 40)->nullable();
            $table->unsignedBigInteger('quoted_total_minor')->nullable();
            $table->unsignedBigInteger('final_fare_minor')->nullable();
            $table->unsignedBigInteger('cancellation_fee_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('route_distance_m')->nullable();
            $table->unsignedInteger('route_duration_s')->nullable();
            $table->string('payment_method_kind', 24)->nullable();
            $table->string('payment_method_ref', 64)->nullable();

            // Trip PIN: an HMAC for checking, an encrypted copy only the passenger is shown.
            $table->string('pin_hash', 64)->nullable();
            $table->text('pin_encrypted')->nullable();
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // Search state.
            $table->timestamp('search_started_at')->nullable();
            $table->unsignedSmallInteger('search_round')->default(0);
            $table->unsignedSmallInteger('offers_sent')->default(0);
            $table->unsignedSmallInteger('dispatch_priority')->default(0);
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamp('pending_offer_expires_at')->nullable();
            $table->timestamp('dispatch_lease_until')->nullable();
            $table->string('dispatch_lease_owner', 36)->nullable();

            // Stage stamps not covered by the 2026-10-19 lifecycle columns.
            $table->timestamp('driver_arriving_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 60)->nullable();
            $table->string('needs_review_reason', 255)->nullable();

            // Scheduled rides.
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('requote_required_at')->nullable();
            $table->string('requote_quote_id', 64)->nullable();
            $table->unsignedBigInteger('requote_total_minor')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->index(['status', 'next_dispatch_at'], 'rides_status_next_dispatch_index');
            $table->index(['status', 'scheduled_for'], 'rides_status_scheduled_for_index');
        });

        Schema::create('driver_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 16)->default('offline');
            $table->timestamp('since')->nullable();
            $table->timestamp('online_since')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            // Latest position only (overwritten, never a history): the dispatch index.
            // The geo domain's driver_locations/trip_points own trails and retention.
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->unsignedBigInteger('current_ride_id')->nullable()->unique('driver_availability_current_ride_unique');
            $table->timestamp('last_trip_ended_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('location_version')->default(0);
            $table->timestamps();

            $table->index(['state', 'last_lat', 'last_lng'], 'driver_availability_state_position_index');
        });

        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            // pending, accepted, declined, expired, withdrawn
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('pending_ride_id')->nullable()->unique('ride_offers_pending_ride_unique');
            $table->unsignedBigInteger('pending_driver_id')->nullable()->unique('ride_offers_pending_driver_unique');
            $table->unsignedSmallInteger('round')->default(0);
            $table->unsignedInteger('radius_m')->nullable();
            $table->unsignedInteger('pickup_distance_m')->nullable();
            $table->unsignedInteger('pickup_eta_s')->nullable();
            $table->string('eta_method', 24)->nullable();
            $table->decimal('score', 12, 2)->nullable();
            $table->json('score_terms')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('reason', 40)->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->index(['ride_id', 'status']);
            $table->index(['driver_id', 'status', 'responded_at']);
            $table->index(['driver_id', 'version']);
        });

        Schema::create('ride_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('actor_type', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 60)->nullable();
            $table->json('context')->nullable();
            $table->string('transition_key', 120)->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['ride_id', 'sequence']);
            $table->unique(['ride_id', 'transition_key']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedBigInteger('ride_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('ride_events');
        Schema::dropIfExists('ride_offers');
        Schema::dropIfExists('driver_availability');

        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_status_next_dispatch_index');
            $table->dropIndex('rides_status_scheduled_for_index');
            $table->dropUnique('rides_active_driver_unique');
            $table->dropUnique('rides_active_passenger_unique');
            $table->dropIndex(['fare_quote_id']);
            $table->dropColumn([
                'version', 'active_driver_id', 'active_passenger_id',
                'fare_quote_id', 'quote_snapshot', 'category_code', 'quoted_total_minor', 'final_fare_minor',
                'cancellation_fee_minor', 'currency', 'route_distance_m', 'route_duration_s',
                'payment_method_kind', 'payment_method_ref',
                'pin_hash', 'pin_encrypted', 'pin_attempts', 'pin_locked_until',
                'search_started_at', 'search_round', 'offers_sent', 'dispatch_priority', 'next_dispatch_at',
                'pending_offer_expires_at', 'dispatch_lease_until', 'dispatch_lease_owner',
                'driver_arriving_at', 'arrived_at', 'no_show_at', 'failed_at', 'failure_reason', 'needs_review_reason',
                'scheduled_for', 'requote_required_at', 'requote_quote_id', 'requote_total_minor', 'reminder_sent_at',
            ]);
        });
    }
};
