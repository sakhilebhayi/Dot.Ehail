<?php

namespace App\Domain\Verification;

use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Log;

/**
 * A driver may go online in a vehicle only when:
 *   - they have a driver profile and it is approved (not pending, not suspended);
 *   - the vehicle is theirs and active;
 *   - every mandatory driver document and every mandatory document for that vehicle's category
 *     is approved, not past its expiry date and not older than the category allows.
 * The third rule can be switched off per deployment (ehail_safety.verification.require_documents)
 * only while a market's document list is unconfirmed; the first two never.
 */
class VerificationDriverEligibility implements DriverEligibility
{
    private const MESSAGES = [
        'missing' => ['document_missing', 'Upload: %s.'],
        'pending_review' => ['document_pending_review', 'Waiting for review: %s.'],
        'rejected' => ['document_rejected', 'Rejected, upload a new one: %s.'],
        'resubmission_requested' => ['document_resubmission_requested', 'Please upload again: %s.'],
        'expired' => ['document_expired', 'Expired, upload the current one: %s.'],
        'too_old' => ['document_too_old', 'Too old for this vehicle category, upload a recent one: %s.'],
    ];

    public function __construct(private readonly VerificationService $verification) {}

    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult
    {
        $reasons = [];
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();
        $enforce = (bool) config('ehail_safety.verification.require_documents', true);

        if ($profile === null) {
            return new EligibilityResult([$this->reason('no_driver_profile', 'driver', null, 'Apply to drive first.')], $enforce);
        }

        if ($profile->status === 'suspended') {
            $reasons[] = $this->reason('driver_suspended', 'driver', null, 'Your driver account is suspended. Contact support.');
        } elseif ($profile->status !== 'approved') {
            $reasons[] = $this->reason('driver_not_approved', 'driver', null, 'Your driver application has not been approved yet.');
        }

        if ((int) $vehicle->driver_profile_id !== (int) $profile->id) {
            $reasons[] = $this->reason('vehicle_not_owned', 'vehicle', null, 'That vehicle is not registered to you.');

            return new EligibilityResult($reasons, $enforce);
        }

        if (! $vehicle->is_active) {
            $reasons[] = $this->reason('vehicle_inactive', 'vehicle', null, 'Make this vehicle your active vehicle first.');
        }

        if (! $enforce) {
            Log::warning('Go-online check ran with document verification switched off.', ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

            return new EligibilityResult($reasons, false);
        }

        foreach (['driver' => $this->verification->driverStatus($profile), 'vehicle' => $this->verification->vehicleStatus($vehicle)] as $subject => $status) {
            foreach ($status['items'] as $item) {
                if ($item['satisfied']) {
                    continue;
                }

                [$code, $sentence] = self::MESSAGES[$item['state']] ?? ['document_missing', 'Upload: %s.'];
                $reasons[] = $this->reason($code, $subject, $item['type'], sprintf($sentence, $item['label']));
            }
        }

        return new EligibilityResult($reasons, true);
    }

    /**
     * @return array{code: string, subject: string, type: ?string, message: string}
     */
    private function reason(string $code, string $subject, ?string $type, string $message): array
    {
        return ['code' => $code, 'subject' => $subject, 'type' => $type, 'message' => $message];
    }
}
