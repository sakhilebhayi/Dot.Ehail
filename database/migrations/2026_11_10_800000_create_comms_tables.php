<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 comms (docs/R46_COMMS.md): per-person channel choices, the delivery log (one row per event,
 * person and channel, which is also the work queue), Web Push subscriptions and confirmed SMS numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('channel', 16); // push | email | sms
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel'], 'notification_preferences_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            // One occurrence of one event ("ride:12:driver_assigned:0", "n:<notification uuid>").
            $table->string('event_key', 160);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // in_app | push | email | sms
            $table->string('category', 40);
            $table->string('type', 60);
            // The in-app record this copy is made from (the content is read from it, never stored twice).
            $table->uuid('notification_id')->nullable();
            $table->string('status', 12); // queued | sending | sent | failed | skipped
            $table->string('reason', 160)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('collapse_key', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['event_key', 'user_id', 'channel'], 'notification_deliveries_idempotency');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['notification_id']);
            $table->index(['user_id', 'collapse_key', 'channel']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint'); // encrypted
            $table->char('endpoint_hash', 64)->unique();
            $table->text('p256dh'); // encrypted
            $table->text('auth_secret'); // encrypted
            $table->string('device_label', 60)->nullable(); // "Chrome on Android"; never the full user agent
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('notification_sms_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('number'); // encrypted, E.164
            $table->timestamp('verified_at')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->timestamp('code_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_sms_numbers');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
    }
};
