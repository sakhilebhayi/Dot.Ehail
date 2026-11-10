<?php

namespace App\Domain\Fleet;

use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Document expiry overview for every driver and vehicle of a fleet, read from the verification domain. Status
 * only: the type, its state (approved, waiting for review, missing, rejected, expired), the expiry date and the days
 * left. Never a file, a number or a photo (fleet owners cannot open verification documents, D-503).
 */
final class DocumentOverview
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly FleetAccess $access,
    ) {}

    /**
     * @return list<array{subject: string, subject_label: string, subject_id: int, type: string, label: string, state: string, satisfied: bool, expires_on: ?string, days_left: ?int, expiring: bool, renewal_pending: bool}>
     */
    public function rows(User $viewer, Fleet $fleet): array
    {
        $rows = [];

        foreach ($this->access->drivers($viewer, $fleet) as $profile) {
            foreach ($this->verification->driverStatus($profile)['items'] as $item) {
                $rows[] = $this->row('driver', (string) ($profile->user?->name ?? 'Driver #'.$profile->id), $profile->id, $item);
            }
        }

        foreach ($this->access->vehicleQuery($viewer, $fleet)->whereNull('retired_at')->orderBy('plate_number')->get() as $vehicle) {
            foreach ($this->vehicleStatus($vehicle)['items'] as $item) {
                $rows[] = $this->row('vehicle', $vehicle->plate_number, $vehicle->id, $item);
            }
        }

        return $rows;
    }

    /**
     * The verification status of one vehicle, also when nobody holds it (its documents stay with the profile of
     * the driver who last held it until it is assigned again).
     *
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    public function vehicleStatus(Vehicle $vehicle): array
    {
        if ($vehicle->driver_profile_id !== null) {
            return $this->verification->vehicleStatus($vehicle);
        }

        $probe = clone $vehicle;
        $probe->driver_profile_id = (int) (VerificationDocument::query()->where('vehicle_id', $vehicle->id)->latest('id')->value('driver_profile_id') ?? 0);

        return $this->verification->vehicleStatus($probe);
    }

    /**
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    public function driverStatus(DriverProfile $profile): array
    {
        return $this->verification->driverStatus($profile);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{subject: string, subject_label: string, subject_id: int, type: string, label: string, state: string, satisfied: bool, expires_on: ?string, days_left: ?int, expiring: bool, renewal_pending: bool}
     */
    private function row(string $subject, string $subjectLabel, int $subjectId, array $item): array
    {
        $expires = $item['expires_on'] ? Carbon::parse($item['expires_on']) : null;
        $daysLeft = $expires ? (int) today()->diffInDays($expires, false) : null;
        $window = (int) config('ehail_fleet.alerts.document_expiry_days', 30);

        return [
            'subject' => $subject,
            'subject_label' => $subjectLabel,
            'subject_id' => $subjectId,
            'type' => (string) $item['type'],
            'label' => (string) $item['label'],
            'state' => (string) $item['state'],
            'satisfied' => (bool) $item['satisfied'],
            'expires_on' => $item['expires_on'],
            'days_left' => $daysLeft,
            'expiring' => $item['satisfied'] && $daysLeft !== null && $daysLeft <= $window,
            'renewal_pending' => (bool) ($item['renewal_pending'] ?? false),
        ];
    }
}
