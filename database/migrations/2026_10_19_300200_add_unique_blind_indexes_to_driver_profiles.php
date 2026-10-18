<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uniqueness of a licence / ID number, now enforced on the blind index.
     * NULL is allowed (and repeats freely), which is how a legacy clash is
     * left for a human instead of failing the deploy.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unique('license_number_hash', 'driver_profiles_license_number_hash_unique');
            $table->unique('id_number_hash', 'driver_profiles_id_number_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropUnique('driver_profiles_license_number_hash_unique');
            $table->dropUnique('driver_profiles_id_number_hash_unique');
        });
    }
};
