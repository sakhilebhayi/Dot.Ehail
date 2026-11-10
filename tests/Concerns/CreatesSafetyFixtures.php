<?php

namespace Tests\Concerns;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;

/**
 * Builders for the R-46 safety tests. Real models and services only; files are generated with GD
 * so the EXIF and type-sniffing paths run for real.
 */
trait CreatesSafetyFixtures
{
    use ApprovesDriverDocuments;
    use CreatesEhailFixtures;

    /** A JPEG carrying an EXIF APP1 segment with a recognisable marker (stands in for GPS data). */
    protected function jpegWithExif(string $marker = 'GPS-SECRET-MARKER-123', int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // Minimal little-endian TIFF header with zero IFD entries, followed by the marker text.
        $exif = "Exif\0\0"."II*\0".pack('V', 8).pack('v', 0).pack('V', 0).$marker;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    protected function jpegUpload(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->jpegWithExif());
    }

    protected function pdfUpload(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * Approved driver with an active vehicle and every mandatory document approved (written
     * directly as approved rows; the review flow itself is covered by VerificationTest).
     *
     * @return array{0: User, 1: DriverProfile, 2: Vehicle}
     */
    protected function makeVerifiedDriver(string $vehicleType = 'standard', bool $online = false): array
    {
        $user = $this->makeApprovedDriver($vehicleType, false);
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $user->id)->firstOrFail();
        $vehicle = $profile->vehicles()->firstOrFail();

        $this->approveAllDocuments($profile, $vehicle);

        if ($online) {
            $profile->update(['is_online' => true]);
        }

        return [$user, $profile, $vehicle];
    }

    /**
     * An active ride (driver accepted) between a new passenger and a verified driver.
     *
     * @return array{0: Ride, 1: User, 2: User}
     */
    protected function makeActiveRide(string $status = 'in_progress'): array
    {
        [$driver, $profile, $vehicle] = $this->makeVerifiedDriver();
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);

        $ride = $this->makeRide([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'status' => $status,
            'accepted_at' => now(),
            'started_at' => $status === 'in_progress' ? now() : null,
            'pickup_address' => '12 Secret Home Road, Soweto',
            'dropoff_address' => '99 Private Office Park, Sandton',
        ], $passenger);

        return [$ride, $passenger, $driver];
    }
}
