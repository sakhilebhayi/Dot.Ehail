<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passenger app (R-46 wave 2, D-801): places a passenger chose to save (home, work, up to a few others).
 * Stored only because the passenger pressed "Save"; deleted with the account (cascade) or by the passenger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passenger_saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // home | work | other
            $table->string('kind', 8);
            $table->string('label', 60);
            $table->string('address', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_saved_places');
    }
};
