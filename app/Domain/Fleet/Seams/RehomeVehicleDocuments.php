<?php

namespace App\Domain\Fleet\Seams;

use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Vehicle;

/**
 * Re-points the vehicle-subject verification documents of a vehicle to its new holder's profile. Only the holder
 * reference moves: the uploader (user_id, who may open the file), status, expiry and review history stay as they
 * were. Driver-subject documents (identity, licence, PrDP) are never touched.
 */
class RehomeVehicleDocuments implements VehicleDocumentCustody
{
    public function moveTo(Vehicle $vehicle, DriverProfile $holder): int
    {
        return VerificationDocument::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('subject', VerificationDocument::SUBJECT_VEHICLE)
            ->where('driver_profile_id', '!=', $holder->id)
            ->update(['driver_profile_id' => $holder->id, 'updated_at' => now()]);
    }
}
