<?php

namespace App\Domain\Safety;

use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * Facts each party may see about the other during a ride. Factual only: no scores invented, no
 * raw rating decimals on these surfaces (a band instead), nothing that is not on record.
 */
class TrustSignals
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * Rating band from an average and a count. Below the minimum count there is no band ("new").
     */
    public static function band(?float $average, int $count): array
    {
        $min = (int) config('ehail_safety.ratings.min_count_for_band', 5);

        // A driver with a few ratings is not "new": say how far they are from a band instead (jury item 15, D-2008).
        if ($count < $min || $average === null) {
            return ['band' => 'new', 'label' => $count === 0 ? 'No ratings yet' : 'Fewer than '.$min.' ratings so far', 'count' => $count];
        }

        [$band, $label] = match (true) {
            $average >= 4.8 => ['excellent', 'Excellent'],
            $average >= 4.5 => ['very_good', 'Very good'],
            $average >= 4.0 => ['good', 'Good'],
            default => ['mixed', 'Mixed reviews'],
        };

        return ['band' => $band, 'label' => $label, 'count' => $count];
    }

    /** @return array{average: ?float, count: int} ratings counted towards the average (not excluded on appeal) */
    public static function ratingStats(int $userId, string $role): array
    {
        $query = RideRating::query()->where('rated_user_id', $userId)->where('rated_role', $role)->whereNull('excluded_at');

        return ['average' => ($avg = (clone $query)->avg('rating')) === null ? null : (float) $avg, 'count' => (clone $query)->count()];
    }

    /**
     * What the passenger sees about the driver and vehicle while the ride is active.
     *
     * @return array<string, mixed>|null
     */
    public function driverCardFor(Ride $ride): ?array
    {
        if ($ride->driver_id === null) {
            return null;
        }

        $driver = User::find($ride->driver_id);
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $ride->driver_id)->first();
        $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;
        $stats = self::ratingStats((int) $ride->driver_id, RideRating::ROLE_DRIVER);
        $driverStatus = $profile ? $this->verification->driverStatus($profile) : null;
        $photo = collect($driverStatus['items'] ?? [])->firstWhere('type', 'profile_photo');

        return [
            'first_name' => RideSafety::firstName($driver?->name, 'Your driver'),
            'photo_url' => ($photo['satisfied'] ?? false) ? route('safety.rides.driver-photo', $ride) : null,
            'rating' => self::band($stats['average'], $stats['count']),
            'completed_trips' => (int) ($profile->total_rides ?? 0),
            'verified' => $driverStatus !== null && $driverStatus['status'] === 'verified',
            'vehicle' => $vehicle ? [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'colour' => $vehicle->color,
                'registration' => $vehicle->plate_number,
                'verified' => $this->verification->vehicleStatus($vehicle)['status'] === 'verified',
            ] : null,
            'check' => 'Check the registration and the driver\'s face before you get in. Never get into a car that does not match.',
        ];
    }

    /**
     * What the driver sees about the passenger: verification facts and history counts only.
     *
     * @return array<string, mixed>
     */
    public function passengerCardFor(Ride $ride): array
    {
        $passenger = User::find($ride->passenger_id);
        $months = $passenger?->created_at ? (int) $passenger->created_at->diffInMonths(now()) : 0;
        $stats = self::ratingStats((int) $ride->passenger_id, RideRating::ROLE_PASSENGER);

        return [
            'first_name' => RideSafety::firstName($passenger?->name, 'Your passenger'),
            'email_verified' => $passenger?->email_verified_at !== null,
            // Dot.Ehail does not collect or verify phone numbers yet: reported as unknown, never as true.
            'phone_verified' => null,
            'account_age' => match (true) {
                $months < 1 => 'Joined less than a month ago',
                $months < 12 => 'Member for '.$months.' month'.($months === 1 ? '' : 's'),
                default => 'Member for '.intdiv($months, 12).' year'.(intdiv($months, 12) === 1 ? '' : 's'),
            },
            'completed_trips' => Ride::query()->where('passenger_id', $ride->passenger_id)->where('status', 'completed')->count(),
            'rating' => self::band($stats['average'], $stats['count']),
        ];
    }

    public function driverPhotoPath(Ride $ride): ?array
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $ride->driver_id)->first();

        if ($profile === null) {
            return null;
        }

        $item = collect($this->verification->driverStatus($profile)['items'])->firstWhere('type', 'profile_photo');

        if (! ($item['satisfied'] ?? false)) {
            return null;
        }

        $doc = VerificationDocument::find($item['document_id']);

        return $doc && Storage::disk($doc->disk)->exists($doc->file_path) ? ['disk' => $doc->disk, 'path' => $doc->file_path, 'mime' => $doc->mime] : null;
    }
}
