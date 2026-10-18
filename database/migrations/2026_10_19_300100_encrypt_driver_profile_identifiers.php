<?php

use App\Support\DriverIdentifiers;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Data migration: encrypt plaintext licence/ID numbers already stored and
     * fill the blind indexes. Idempotent (an already-encrypted value is left
     * alone), so re-running it, or running it after new rows were written
     * mid-deploy, is harmless. Back up driver_profiles before the first run:
     * the encrypted values are only readable with this environment's APP_KEY.
     */
    public function up(): void
    {
        DriverIdentifiers::encryptLegacyRows();
    }

    public function down(): void
    {
        DriverIdentifiers::decryptRows();
    }
};
