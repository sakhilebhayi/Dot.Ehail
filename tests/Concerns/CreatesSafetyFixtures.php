<?php

namespace Tests\Concerns;

use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
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
