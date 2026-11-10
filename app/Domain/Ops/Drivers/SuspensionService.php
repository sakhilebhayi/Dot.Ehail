<?php

namespace App\Domain\Ops\Drivers;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Ops\Audit\OpsAudit;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suspending and reinstating drivers. A suspension sets driver_profiles.status to suspended (the verification
 * gate then refuses going online: code driver_suspended) and takes an online driver offline at once; a driver on a
 * trip finishes it (dispatch offers rides only to approved profiles, so no new ride reaches them). The driver may
 * appeal once per suspension; a different operator from the one who suspended decides it. Every step is audited.
 */
class SuspensionService
{
    /** @var array<string, string> */
    public const REASONS = [
        'safety_incident' => 'Safety incident under investigation',
        'passenger_reports' => 'Repeated passenger reports',
        'document_fraud' => 'Suspected false or altered document',
        'cancellation_pattern' => 'Cancellation pattern after acceptance',
        'fare_misconduct' => 'Fare or payment misconduct',
        'conduct' => 'Conduct towards passengers or staff',
        'vehicle_unsafe' => 'Vehicle not roadworthy',
        'other' => 'Other (see note)',
    ];

    public function __construct(private readonly AvailabilityService $availability, private readonly OpsAudit $audit) {}

    public function activeFor(User $driver): ?DriverSuspension
    {
        return DriverSuspension::query()->where('user_id', $driver->id)->whereNull('lifted_at')->latest('id')->first();
    }

    public function suspend(User $operator, User $driver, string $reasonCode, ?string $note): DriverSuspension
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['driver' => 'This person has no driver profile.']);
        }
        if ($operator->is($driver)) {
            throw ValidationException::withMessages(['driver' => 'You cannot suspend yourself.']);
        }
        if ($profile->status === 'suspended' || $this->activeFor($driver) !== null) {
            throw ValidationException::withMessages(['driver' => 'This driver is already suspended.']);
        }
        if (! isset(self::REASONS[$reasonCode])) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a reason.']);
        }
        if ($reasonCode === 'other' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why in the note.']);
        }

        $suspension = DB::transaction(function () use ($operator, $driver, $profile, $reasonCode, $note): DriverSuspension {
            $suspension = DriverSuspension::create([
                'driver_profile_id' => $profile->id,
                'user_id' => $driver->id,
                'reason_code' => $reasonCode,
                'note' => $note,
                'previous_status' => (string) $profile->status,
                'suspended_by' => $operator->id,
                'suspended_at' => now(),
            ]);

            $profile->forceFill(['status' => 'suspended'])->save();

            return $suspension;
        });

        $wentOffline = false;
        $row = $this->availability->forDriver($driver);
        if ($row !== null && in_array($row->state, [DriverAvailability::ONLINE, DriverAvailability::PAUSED], true)) {
            $this->availability->goOffline($driver);
            $wentOffline = true;
        }

        $this->audit->action('ops.driver_suspended', $operator, $suspension, [
            'driver_id' => $driver->id, 'reason_code' => $reasonCode, 'taken_offline' => $wentOffline,
            'on_trip' => $row?->state === DriverAvailability::ON_TRIP,
        ]);

        return $suspension;
    }

    public function lift(User $operator, DriverSuspension $suspension, string $note): DriverSuspension
    {
        if (! $suspension->isActive()) {
            throw ValidationException::withMessages(['suspension' => 'This suspension has already ended.']);
        }

        DB::transaction(function () use ($operator, $suspension, $note): void {
            $suspension->forceFill(['lifted_at' => now(), 'lifted_by' => $operator->id, 'lift_note' => $note])->save();

            $profile = DriverProfile::withoutGlobalScope('user')->find($suspension->driver_profile_id);
            if ($profile !== null && $profile->status === 'suspended') {
                $profile->forceFill(['status' => $suspension->previous_status === 'suspended' ? 'approved' : $suspension->previous_status])->save();
            }
        });

        $this->audit->action('ops.driver_reinstated', $operator, $suspension, ['driver_id' => $suspension->user_id]);

        return $suspension->refresh();
    }

    /** The driver's own appeal (one per suspension). */
    public function appeal(User $driver, DriverSuspension $suspension, string $text): DriverSuspension
    {
        if ($suspension->user_id !== $driver->id) {
            abort(404);
        }
        if (! $suspension->isActive() || $suspension->appeal_status !== 'none') {
            throw ValidationException::withMessages(['appeal' => 'This suspension cannot be appealed again.']);
        }

        $suspension->forceFill(['appeal_status' => 'open', 'appeal_text' => $text, 'appealed_at' => now()])->save();
        $this->audit->action('ops.suspension_appealed', $driver, $suspension, []);

        return $suspension;
    }

    public function decideAppeal(User $operator, DriverSuspension $suspension, string $decision, string $note): DriverSuspension
    {
        if ($suspension->appeal_status !== 'open') {
            throw ValidationException::withMessages(['appeal' => 'There is no open appeal on this suspension.']);
        }
        if ($suspension->suspended_by === $operator->id) {
            throw ValidationException::withMessages(['appeal' => 'Someone other than the person who suspended this driver must decide the appeal.']);
        }
        if (! in_array($decision, ['upheld', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose upheld or rejected.']);
        }

        $suspension->forceFill([
            'appeal_status' => $decision, 'appeal_decided_by' => $operator->id, 'appeal_decided_at' => now(), 'appeal_note' => $note,
        ])->save();

        $this->audit->action('ops.suspension_appeal_decided', $operator, $suspension, ['decision' => $decision]);

        if ($decision === 'upheld') {
            $this->lift($operator, $suspension, 'Appeal upheld: '.$note);
        }

        return $suspension->refresh();
    }
}
