<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ratings: one per (ride, rater), 1-5, a comment up to 500 characters, and
     * a record of who was rated and in which role.
     *
     * The original table allowed one rating per ride in total (ride_id unique)
     * and nothing in the app ever wrote to it. The unique index becomes
     * (ride_id, rated_by). The 1-5 rule is a database CHECK on MySQL/Postgres
     * and a pair of triggers on SQLite, which cannot add a CHECK to an
     * existing table.
     */
    public function up(): void
    {
        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->foreignId('rated_user_id')->nullable()->after('rated_by')->constrained('users')->nullOnDelete();
            $table->string('rated_role', 20)->nullable()->after('rated_user_id');
            $table->string('comment', 500)->nullable()->change();
        });

        // Any rows written before this migration: the ratee is the other party.
        DB::table('ride_ratings')
            ->join('rides', 'rides.id', '=', 'ride_ratings.ride_id')
            ->select('ride_ratings.id', 'ride_ratings.rated_by', 'rides.passenger_id', 'rides.driver_id')
            ->orderBy('ride_ratings.id')
            ->each(function ($row) {
                $ratedByPassenger = (int) $row->rated_by === (int) $row->passenger_id;

                DB::table('ride_ratings')->where('id', $row->id)->update([
                    'rated_user_id' => $ratedByPassenger ? $row->driver_id : $row->passenger_id,
                    'rated_role' => $ratedByPassenger ? 'driver' : 'passenger',
                ]);
            });

        Schema::table('ride_ratings', function (Blueprint $table) {
            // Added before the old unique index is dropped so the ride_id
            // foreign key always has an index to lean on.
            $table->unique(['ride_id', 'rated_by'], 'ride_ratings_ride_id_rated_by_unique');
            $table->index(['rated_user_id', 'rated_role'], 'ride_ratings_rated_user_id_rated_role_index');
        });

        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->dropUnique('ride_ratings_ride_id_unique');
        });

        $this->addRatingRangeGuard();
    }

    public function down(): void
    {
        $this->dropRatingRangeGuard();

        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->unique('ride_id', 'ride_ratings_ride_id_unique');
        });

        Schema::table('ride_ratings', function (Blueprint $table) {
            $table->dropUnique('ride_ratings_ride_id_rated_by_unique');
            $table->dropIndex('ride_ratings_rated_user_id_rated_role_index');
            $table->dropConstrainedForeignId('rated_user_id');
            $table->dropColumn('rated_role');
        });
    }

    private function addRatingRangeGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $name) {
                DB::statement("CREATE TRIGGER ride_ratings_rating_range_{$name} BEFORE {$event} ON ride_ratings
                    WHEN NEW.rating IS NULL OR NEW.rating < 1 OR NEW.rating > 5
                    BEGIN SELECT RAISE(ABORT, 'ride_ratings.rating must be between 1 and 5'); END");
            }

            return;
        }

        DB::statement('ALTER TABLE ride_ratings ADD CONSTRAINT ride_ratings_rating_range CHECK (rating BETWEEN 1 AND 5)');
    }

    private function dropRatingRangeGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS ride_ratings_rating_range_insert');
            DB::statement('DROP TRIGGER IF EXISTS ride_ratings_rating_range_update');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ride_ratings DROP CONSTRAINT IF EXISTS ride_ratings_rating_range');

            return;
        }

        DB::statement('ALTER TABLE ride_ratings DROP CHECK ride_ratings_rating_range');
    }
};
