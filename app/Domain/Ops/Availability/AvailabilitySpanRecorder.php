<?php

namespace App\Domain\Ops\Availability;

use App\Domain\Dispatch\Models\DriverAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Measures online time for insights (driver utilisation). Dispatch keeps only the current state on
 * driver_availability; this observer closes the open span and opens a new one whenever the state changes, so time
 * online and time on trip are measured from the moment this shipped, never reconstructed for the past (D-1004).
 */
class AvailabilitySpanRecorder
{
    public function saved(DriverAvailability $availability): void
    {
        $created = $availability->wasRecentlyCreated && $availability->getChanges() === [];
        if (! $created && ! $availability->wasChanged('state')) {
            return;
        }

        $at = $availability->since instanceof Carbon ? $availability->since : now();
        if ($at->greaterThan(now())) {
            $at = now();
        }

        // Measurement must never break dispatch: a failure here is reported, not thrown.
        try {
            $this->record($availability, $at);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function record(DriverAvailability $availability, Carbon $at): void
    {
        DB::transaction(function () use ($availability, $at): void {
            AvailabilitySpan::query()
                ->where('driver_id', $availability->user_id)
                ->whereNull('ended_at')
                ->update(['ended_at' => $at]);

            AvailabilitySpan::create([
                'driver_id' => $availability->user_id,
                'state' => (string) $availability->state,
                'started_at' => $at,
            ]);
        });
    }
}
