<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roadmap item (wiki.md §8): "Driver document/inspection workflow
     * (license, ID, vehicle inspection records referenced in README but
     * not modeled beyond license_number/id_number)". A separate table
     * rather than columns on driver_profiles -- a driver can have more
     * than one document per type over time (a license renewal, a repeat
     * vehicle inspection), and the append-only history is itself useful
     * to a fleet owner reviewing an application.
     */
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['license', 'id', 'vehicle_inspection']);
            $table->string('file_path');
            $table->string('original_filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
