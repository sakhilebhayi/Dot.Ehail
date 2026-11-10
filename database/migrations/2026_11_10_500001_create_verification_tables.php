<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-46 verification (docs/R46_SAFETY.md). verification_documents extends the existing
     * driver_documents idea with a type per market, a review status, an expiry date and a review
     * history (verification_reviews, append-only). Existing driver_documents rows are carried over
     * as "pending" so an operator reviews them once under the new rules; the file itself is not
     * copied (same disk, same path) and the legacy row is kept.
     */
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('market', 2)->default('ZA');
            $table->string('subject', 10);
            $table->string('type', 40);
            $table->string('status', 30)->default('pending')->index();
            $table->string('disk', 40);
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->date('expires_on')->nullable()->index();
            $table->timestamp('submitted_at');
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 40)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('warned_30_at')->nullable();
            $table->timestamp('warned_7_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('verification_documents')->nullOnDelete();
            $table->foreignId('legacy_driver_document_id')->nullable()->constrained('driver_documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_profile_id', 'type', 'status']);
            $table->index(['vehicle_id', 'type', 'status']);
        });

        Schema::create('verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->carryOverLegacyDocuments();
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_reviews');
        Schema::dropIfExists('verification_documents');
    }

    private function carryOverLegacyDocuments(): void
    {
        $map = ['license' => 'drivers_licence', 'id' => 'identity_document', 'vehicle_inspection' => 'roadworthy_certificate'];

        DB::table('driver_documents')
            ->join('driver_profiles', 'driver_profiles.id', '=', 'driver_documents.driver_profile_id')
            ->select('driver_documents.*', 'driver_profiles.user_id')
            ->orderBy('driver_documents.id')
            ->each(function ($row) use ($map) {
                $type = $map[$row->type] ?? null;

                // A roadworthy certificate belongs to a vehicle; an inspection uploaded without one cannot be placed.
                if ($type === null || ($type === 'roadworthy_certificate' && $row->vehicle_id === null)) {
                    return;
                }

                $extension = strtolower(pathinfo((string) $row->original_filename, PATHINFO_EXTENSION));

                DB::table('verification_documents')->insert([
                    'user_id' => $row->user_id,
                    'driver_profile_id' => $row->driver_profile_id,
                    'vehicle_id' => $type === 'roadworthy_certificate' ? $row->vehicle_id : null,
                    'market' => 'ZA',
                    'subject' => $type === 'roadworthy_certificate' ? 'vehicle' : 'driver',
                    'type' => $type,
                    'status' => 'pending',
                    'disk' => 'driver-documents',
                    'file_path' => $row->file_path,
                    'original_filename' => $row->original_filename,
                    'mime' => $extension === 'pdf' ? 'application/pdf' : ($extension === 'png' ? 'image/png' : 'image/jpeg'),
                    'size_bytes' => 0,
                    'sha256' => str_repeat('0', 64),
                    'submitted_at' => $row->created_at ?? now(),
                    'legacy_driver_document_id' => $row->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
