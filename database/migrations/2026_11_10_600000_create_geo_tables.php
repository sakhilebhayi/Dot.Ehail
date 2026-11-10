<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-46 geo tables (docs/R46_ARCHITECTURE.md section 1, docs/R46_GEO.md). Polygons are JSON [[lat, lng], ...] with
 * a stored bounding box for the cheap prefilter; no PostGIS (the shared host's PostgreSQL/MySQL may not have it,
 * and SQLite runs the tests), so containment and distances are computed in PHP after a bbox query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->json('polygon');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->boolean('is_active')->default(true);
            // Seeded reference shapes are approximate until an operator reviews them before launch.
            $table->boolean('is_approximate')->default(false);
            $table->string('source_note')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'min_lat', 'max_lat']);
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('name');
            // airport | special | restricted | surge_eligible
            $table->string('kind', 32);
            $table->json('polygon');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('max_lng', 10, 7);
            // Pricing hook: the pricing domain maps this code to a fee in its rule sets (e.g. airport_pickup).
            $table->string('fee_code', 64)->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_approximate')->default(false);
            $table->string('source_note')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'kind']);
            $table->index(['min_lat', 'max_lat']);
        });

        // One row per driver: the latest accepted fix, used for matching and for the assigned passenger's view.
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy_m')->nullable();
            $table->float('speed_mps')->nullable();
            $table->float('heading_deg')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['lat', 'lng']);
            $table->index('recorded_at');
        });

        // The sampled trail of a trip in progress. Raw points are deleted after the retention period.
        Schema::create('trip_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy_m')->nullable();
            $table->float('speed_mps')->nullable();
            $table->float('heading_deg')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');

            $table->index(['ride_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::create('route_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('provider', 32);
            $table->string('profile', 32);
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('duration_s');
            $table->text('polyline');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // The planned road route of a ride (what deviation is measured against) and, once the raw trail is
        // purged, the trip's measured distance summary.
        Schema::create('ride_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->nullable();
            $table->unsignedInteger('planned_distance_m')->nullable();
            $table->unsignedInteger('planned_duration_s')->nullable();
            $table->text('planned_polyline')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->unsignedInteger('travelled_m')->nullable();
            $table->unsignedInteger('billable_m')->nullable();
            $table->string('distance_method', 32)->nullable();
            $table->string('distance_quality', 16)->nullable();
            $table->timestamp('trail_purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_routes');
        Schema::dropIfExists('route_cache');
        Schema::dropIfExists('trip_points');
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('zones');
        Schema::dropIfExists('service_areas');
    }
};
