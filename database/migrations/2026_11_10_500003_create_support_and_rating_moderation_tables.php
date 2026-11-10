<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-46 support cases with a message thread (attachments on the private safety-files disk),
     * and rating moderation: a comment can be held or hidden by an operator without touching the
     * star value, and a driver can appeal a rating once (rating_appeals).
     */
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('requester_role', 20);
            $table->string('category', 30)->index();
            $table->string('status', 20)->default('new')->index();
            $table->string('priority', 10)->index();
            $table->string('subject', 160);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ride_payment_id')->nullable()->constrained('ride_payments')->nullOnDelete();
            $table->foreignId('safety_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counterparty_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_due_at')->nullable()->index();
            $table->timestamp('resolution_due_at')->nullable()->index();
            $table->timestamp('first_responded_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
            $table->string('resolution_code', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 20);
            $table->string('visibility', 20)->default('participants');
            $table->text('body');
            $table->string('attachment_disk', 40)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 60)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_case_id', 'id']);
        });

        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->string('comment_status', 20)->default('visible');
            // No foreign key on purpose: on SQLite adding one rebuilds the table, which would drop the
            // rating-range triggers (2026_10_19_200000). The id is an operator's user id.
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_reason', 40)->nullable();
            // Set when an appeal is upheld: the star value stays on record but leaves the average.
            $table->timestamp('excluded_at')->nullable();
        });

        Schema::create('rating_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_rating_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_appeals');

        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->dropColumn(['moderated_by', 'comment_status', 'moderated_at', 'moderation_reason', 'excluded_at']);
        });

        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_cases');
    }
};
