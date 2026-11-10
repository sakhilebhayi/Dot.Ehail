<?php

namespace Tests\Concerns;

use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Vehicle;

/**
 * Approved verification documents for a driver and vehicle (every mandatory type in the market), written
 * directly as approved rows; the upload and review flow itself is covered by the safety VerificationTest.
 * Shared by the safety and dispatch fixtures: since the safety go-online guard, an online driver must be verified.
 */
trait ApprovesDriverDocuments
{
    protected function approveAllDocuments(DriverProfile $profile, Vehicle $vehicle, array $except = []): void
    {
        $requirements = app(DocumentRequirements::class);

        foreach (['driver' => null, 'vehicle' => $vehicle] as $subject => $forVehicle) {
            foreach ($requirements->required($subject, $subject === 'vehicle' ? $vehicle->type : null) as $type => $rule) {
                if (in_array($type, $except, true)) {
                    continue;
                }

                $this->approvedDocument($profile, $type, $forVehicle, $rule['expiry_required'] ? now()->addYear()->toDateString() : null);
            }
        }
    }

    protected function approvedDocument(DriverProfile $profile, string $type, ?Vehicle $vehicle = null, ?string $expiresOn = null): VerificationDocument
    {
        return VerificationDocument::create([
            'user_id' => $profile->user_id,
            'driver_profile_id' => $profile->id,
            'vehicle_id' => $vehicle?->id,
            'market' => 'ZA',
            'subject' => $vehicle ? 'vehicle' : 'driver',
            'type' => $type,
            'status' => DocumentStatus::Approved,
            'disk' => 'driver-documents',
            'file_path' => 'verification/'.$profile->id.'/'.$type.'.jpg',
            'original_filename' => $type.'.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'expires_on' => $expiresOn,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
    }
}
