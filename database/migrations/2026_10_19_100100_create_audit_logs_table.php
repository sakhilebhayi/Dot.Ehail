<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Small append-only trail for the few actions that need one: an operator
     * entering a fare by hand, a driver backing out of an accepted ride (the
     * ride itself re-queues and forgets), someone opening a driver's
     * identifying details. App\Models\AuditLog refuses updates and deletes.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 60)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
