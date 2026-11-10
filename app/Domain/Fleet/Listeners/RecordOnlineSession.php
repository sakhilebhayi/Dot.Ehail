<?php

namespace App\Domain\Fleet\Listeners;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\Models\DriverOnlineSession;

/**
 * Measures time out of the offline state from dispatch's availability row: a session opens when a driver leaves
 * offline and closes when they return to it. Only real transitions are recorded; nothing is estimated. A driver
 * whose app stopped sending heartbeats stays "online" in dispatch until they go offline, and that time is counted
 * the same way (the fleet pages say so).
 */
class RecordOnlineSession
{
    public function handle(DriverAvailability $row): void
    {
        if (! $row->wasChanged('state') && ! $row->wasRecentlyCreated) {
            return;
        }

        $online = $row->state !== DriverAvailability::OFFLINE;
        $open = DriverOnlineSession::query()->where('open_user_id', $row->user_id)->first();

        if ($online && $open === null) {
            // insertOrIgnore: a concurrent request may have opened it a moment ago, and a failed insert would
            // abort the surrounding availability transaction on PostgreSQL.
            DriverOnlineSession::query()->insertOrIgnore([
                'user_id' => $row->user_id,
                'vehicle_id' => $row->vehicle_id,
                'started_at' => now(),
                'open_user_id' => $row->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if (! $online && $open !== null) {
            $open->forceFill(['ended_at' => now(), 'open_user_id' => null])->save();
        }
    }
}
