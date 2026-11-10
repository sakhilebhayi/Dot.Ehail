<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-46 passenger and driver safety (docs/R46_SAFETY.md): trusted contacts, trip share links,
     * incidents with an append-only timeline, the record of every notification an incident caused
     * (what the "what we did" screen reads), safety check-ins after a route deviation or unusual
     * stop, and private reports about another user.
     *
     * Contact details are encrypted at rest (Laravel encrypted casts); email_hash is a keyed blind
     * index so the same address is not added twice. Share tokens are stored as SHA-256 only.
     */
    public function up(): void
    {
        Schema::create('trusted_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('email');
            $table->char('email_hash', 64);
            $table->text('phone')->nullable();
            $table->string('relationship', 40)->nullable();
            $table->char('confirm_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email_hash']);
        });

        Schema::create('trip_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trusted_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();
        });

        Schema::create('safety_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('ride_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_role', 20)->nullable();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('severity', 10);
            $table->string('status', 20)->default('open')->index();
            $table->string('source', 30);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('next_escalation_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('resolution_code', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->unsignedInteger('location_accuracy_m')->nullable();
            $table->timestamp('location_captured_at')->nullable();
            $table->text('description')->nullable();
            $table->json('context')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['safety_incident_id', 'id']);
        });

        Schema::create('incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_incident_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('recipient_kind', 20);
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_label', 120);
            $table->string('purpose', 30);
            $table->string('status', 20)->default('pending');
            $table->string('error', 120)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['safety_incident_id', 'status']);
        });

        Schema::create('safety_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('trigger', 30);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('prompted_at');
            $table->timestamp('respond_by')->index();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('safety_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->json('context')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->timestamps();
        });

        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reporter_role', 20);
            $table->string('category', 40);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('outcome_note')->nullable();
            $table->json('evidence')->nullable();
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
        Schema::dropIfExists('safety_check_ins');
        Schema::dropIfExists('incident_notifications');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('safety_incidents');
        Schema::dropIfExists('trip_shares');
        Schema::dropIfExists('trusted_contacts');
    }
};
