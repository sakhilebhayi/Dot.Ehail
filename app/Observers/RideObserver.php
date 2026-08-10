<?php

namespace App\Observers;

use App\Events\RideStatusUpdated;
use App\Models\Ride;
use App\Notifications\RideCompletedNotification;

/**
 * Fires the in-app notifications that already existed as dead code:
 * `RideCompletedNotification` was written, tested (by manually dispatching
 * it), and rendered by the notification bell, but nothing in the app ever
 * triggered it — no controller or job called `->notify()` on a real status
 * change. This observer closes that gap by watching for the one lifecycle
 * transition the notification was built for: a ride's `status` becoming
 * `completed`.
 *
 * Also broadcasts RideStatusUpdated on ANY status transition (not just
 * completed) so a ride's detail page updates live for its passenger/driver
 * -- a separate, broader concern from the completed-only in-app
 * notification above.
 *
 * Scope note: this only wires the existing *in-app* (`database` channel)
 * notification to a real domain event. It is not the outbound
 * `logistics.trip.completed` ecosystem event described in wiki.md §5/§8 —
 * that still requires the Knowledge Pack publishing pipeline, which does not
 * exist in this repo yet.
 */
class RideObserver
{
    public function updated(Ride $ride): void
    {
        if (! $ride->wasChanged('status')) {
            return;
        }

        broadcast(new RideStatusUpdated($ride))->toOthers();

        if ($ride->status !== 'completed') {
            return;
        }

        $recipients = collect([$ride->passenger, $ride->driver])
            ->filter()
            ->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new RideCompletedNotification($ride));
        }
    }
}
