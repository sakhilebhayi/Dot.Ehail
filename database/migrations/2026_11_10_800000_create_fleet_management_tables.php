<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 fleet management (docs/R46_FLEET.md). Additive only:
 *  - fleets: business profile, kind (independent "fleet of one" or company), payee arrangement, identity-review grant;
 *  - fleet_members: owner and managers with scoped roles (the owner keeps fleets.owner_user_id as the authority);
 *  - vehicles: the fleet that holds the vehicle, who owns it (driver or fleet), doors/features for the catalogue,
 *    odometer, out-of-service and retired flags;
 *  - fleet_vehicle_permits: which drivers may drive which fleet vehicles;
 *  - fleet_vehicle_assignments: who holds a fleet vehicle now; active_vehicle_id is unique while the assignment is
 *    open, so one vehicle never has two active drivers on any engine (nullable-unique behaves as a partial index);
 *  - vehicle_maintenance_records: services, repairs, inspections with odometer and the next service due;
 *  - driver_online_sessions: time in the online state, measured from this migration on (never back-filled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->string('kind', 12)->default('company')->after('status');
            $table->string('trading_name')->nullable()->after('name');
            $table->string('registration_number', 40)->nullable()->after('trading_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('base_city', 80)->nullable();
            $table->string('payee_mode', 12)->nullable(); // null = platform setting | driver | fleet_owner
            $table->timestamp('identity_review_granted_at')->nullable();
            $table->foreignId('identity_review_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        Schema::create('fleet_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // owner | manager
            $table->json('scopes')->nullable(); // manager areas: drivers, vehicles, maintenance, documents, earnings, trips
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['fleet_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        // Adding a foreign key and changing a column both REBUILD the table on SQLite, and the rebuild copies the partial
        // "one active vehicle per driver" index (2026_10_19_200100) WITHOUT its WHERE clause: on a live database where a
        // driver has an inactive second vehicle that fails with "UNIQUE constraint failed: vehicles.driver_profile_id"
        // (found by scripts/ops/upgrade-rehearsal.sh). Drop it first; it is re-created below once the table is final.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS vehicles_one_active_per_driver');
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable()->after('driver_profile_id')->constrained()->nullOnDelete();
            $table->string('owned_by', 8)->default('driver')->after('fleet_id'); // driver | fleet
            $table->unsignedTinyInteger('doors')->nullable()->after('seats');
            $table->json('features')->nullable()->after('doors');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->timestamp('out_of_service_at')->nullable();
            $table->string('out_of_service_reason', 200)->nullable();
            $table->foreignId('out_of_service_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
        });

        // A fleet-held vehicle can be between drivers: the holder becomes optional.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_profile_id')->nullable()->change();
        });

        // The column change rebuilds the table on SQLite and the partial "one active vehicle per driver" index
        // (2026_10_19_200100) must survive it. Re-create it on the engines that have partial indexes.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS vehicles_one_active_per_driver');
            DB::statement('CREATE UNIQUE INDEX vehicles_one_active_per_driver ON vehicles (driver_profile_id) WHERE is_active = true');
        }

        DB::table('vehicles')
            ->join('driver_profiles', 'driver_profiles.id', '=', 'vehicles.driver_profile_id')
            ->select('vehicles.id', 'driver_profiles.fleet_id')
            ->orderBy('vehicles.id')
            ->get()
            ->each(fn ($row) => DB::table('vehicles')->where('id', $row->id)->update(['fleet_id' => $row->fleet_id]));

        Schema::create('fleet_vehicle_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_id', 'driver_profile_id']);
            $table->index(['fleet_id', 'driver_profile_id']);
        });

        Schema::create('fleet_vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            // Set to vehicle_id while the assignment is open, null once ended: unique = one active driver per vehicle.
            $table->unsignedBigInteger('active_vehicle_id')->nullable()->unique('fleet_vehicle_assignments_one_active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['fleet_id', 'started_at']);
            $table->index(['driver_profile_id', 'ended_at']);
        });

        Schema::create('vehicle_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // service | repair | inspection | tyres | other
            $table->date('performed_on');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->string('description', 500);
            $table->bigInteger('cost_minor')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->date('next_service_due_on')->nullable();
            $table->unsignedInteger('next_service_due_km')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'performed_on']);
            $table->index(['fleet_id', 'next_service_due_on']);
        });

        Schema::create('driver_online_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Set to user_id while the session is open: at most one open session per driver.
            $table->unsignedBigInteger('open_user_id')->nullable()->unique('driver_online_sessions_one_open');
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });

        // Owners become members (role owner) so one table answers "who works on this fleet".
        DB::table('fleets')->orderBy('id')->get(['id', 'owner_user_id', 'status', 'updated_at'])->each(function ($fleet) {
            DB::table('fleet_members')->insertOrIgnore([
                'fleet_id' => $fleet->id, 'user_id' => $fleet->owner_user_id, 'role' => 'owner',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // A fleet whose only driver is its owner is a "fleet of one".
            $drivers = DB::table('driver_profiles')->where('fleet_id', $fleet->id)->pluck('user_id');
            $kind = $drivers->count() === 1 && (int) $drivers->first() === (int) $fleet->owner_user_id ? 'independent' : 'company';

            DB::table('fleets')->where('id', $fleet->id)->update([
                'kind' => $kind,
                'approved_at' => $fleet->status === 'approved' ? $fleet->updated_at : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_online_sessions');
        Schema::dropIfExists('vehicle_maintenance_records');
        Schema::dropIfExists('fleet_vehicle_assignments');
        Schema::dropIfExists('fleet_vehicle_permits');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('out_of_service_by');
            $table->dropConstrainedForeignId('fleet_id');
            $table->dropColumn(['owned_by', 'doors', 'features', 'odometer_km', 'out_of_service_at', 'out_of_service_reason', 'retired_at']);
        });

        Schema::dropIfExists('fleet_members');

        Schema::table('fleets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_review_granted_by');
            $table->dropColumn(['kind', 'trading_name', 'registration_number', 'contact_email', 'contact_phone', 'base_city', 'payee_mode', 'identity_review_granted_at', 'approved_at']);
        });
    }
};
