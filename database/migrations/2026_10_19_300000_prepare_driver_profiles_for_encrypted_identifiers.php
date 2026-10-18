<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * license_number and id_number become encrypted. Ciphertext is longer than
     * the plaintext and different every time, so the columns widen to text and
     * their unique indexes move to keyed blind-index columns
     * (App\Support\DriverIdentifiers::blindIndex). The next migration encrypts
     * the existing rows and the one after adds the unique indexes.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('license_number_hash', 64)->nullable()->after('license_number');
            $table->string('id_number_hash', 64)->nullable()->after('id_number');
            $table->dropUnique(['license_number']);
            $table->dropUnique(['id_number']);
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->text('license_number')->change();
            $table->text('id_number')->change();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('license_number')->change();
            $table->string('id_number')->change();
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['license_number_hash', 'id_number_hash']);
            $table->unique('license_number');
            $table->unique('id_number');
        });
    }
};
