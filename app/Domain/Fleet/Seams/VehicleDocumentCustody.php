<?php

namespace App\Domain\Fleet\Seams;

use App\Models\DriverProfile;
use App\Models\Vehicle;

/**
 * Seam to the verification domain: a fleet vehicle's documents (licence disc, roadworthy, insurance, operating
 * licence, photos) belong to the vehicle, but verification_documents also records the driver profile that held the
 * vehicle when they were uploaded, and VerificationService::vehicleStatus() reads them by (holder, vehicle). When
 * the vehicle changes hands its documents must follow it, or a fully verified vehicle would look undocumented.
 *
 * Default: RehomeVehicleDocuments. If the safety domain keys vehicle documents by vehicle alone, bind a no-op.
 */
interface VehicleDocumentCustody
{
    public function moveTo(Vehicle $vehicle, DriverProfile $holder): int;
}
