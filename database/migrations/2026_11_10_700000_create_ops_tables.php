<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 operations centre (ops agent): operator roles, measured online time, driver suspensions with an appeal
 * path, and the indexes the insights queries need (portable: plain b-tree indexes, nothing engine specific).
 */
return new class extends Migration
{
    public function up(): void
    {
        // An operator's roles. A platform operator with no rows keeps full access (decision D-1001).
        Schema::create('ops_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 40);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });

        // Spans of a driver's dispatch state, written by an observer on driver_availability. Online time and
        // utilisation are measured only from these rows (nothing before the first row is guessed).
        Schema::create('ops_availability_spans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['driver_id', 'ended_at']);
            $table->index(['started_at', 'ended_at']);
        });

        Schema::create('driver_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained('driver_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            $table->string('previous_status', 20);
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_note')->nullable();
            $table->string('appeal_status', 20)->default('none');
            $table->text('appeal_text')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->foreignId('appeal_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('appeal_decided_at')->nullable();
            $table->text('appeal_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lifted_at']);
            $table->index('appeal_status');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->index('created_at', 'rides_ops_created_at_index');
            $table->index(['status', 'completed_at'], 'rides_ops_status_completed_at_index');
        });

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->index('created_at', 'safety_incidents_ops_created_at_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_ops_created_at_index');
            $table->index('actor_user_id', 'audit_logs_ops_actor_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_ops_created_at_index');
            $table->dropIndex('audit_logs_ops_actor_index');
        });
        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->dropIndex('safety_incidents_ops_created_at_index');
        });
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_ops_created_at_index');
            $table->dropIndex('rides_ops_status_completed_at_index');
        });
        Schema::dropIfExists('driver_suspensions');
        Schema::dropIfExists('ops_availability_spans');
        Schema::dropIfExists('ops_role_assignments');
    }
};
