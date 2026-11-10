<?php

namespace App\Domain\Rides;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Rides\Events\ScheduledRideReminderDue;
use App\Domain\Rides\Events\ScheduledRideRequoteRequired;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\InvalidRideTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rides booked for later (config ehail_dispatch.scheduled).
 *
 *   booked  -> `scheduled` ("awaiting driver": no driver is reserved ahead of time)
 *   reminder_lead before pickup   ScheduledRideReminderDue (comms delivers it in wave 2)
 *   allocation_lead before pickup the fare is re-quoted. Within price_guarantee_percent
 *                                 of the booked fare (or when pricing cannot quote) the booked
 *                                 fare is honoured; above it the passenger must accept the new
 *                                 fare first (ScheduledRideRequoteRequired). Then `requested`
 *                                 and dispatch starts like an on-demand ride.
 *   driver assigned               "driver confirmed"
 *   pickup + grace passed undispatched   `expired`
 *
 * Reschedule: while still `scheduled` and more than free_change_until_minutes before
 * pickup. Cancel: any time before a driver is on the way, through the normal passenger
 * cancel (the pricing CancellationFees seam decides any fee).
 */
class ScheduledRides
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly Dispatcher $dispatcher,
        private readonly FareQuoteGateway $quotes,
        private readonly RideTimeline $timeline,
    ) {}

    public static function assertBookable(CarbonInterface $at): void
    {
        $cfg = config('ehail_dispatch.scheduled');
        $earliest = now()->addMinutes((int) $cfg['min_lead_minutes']);
        $latest = now()->addDays((int) $cfg['max_lead_days']);

        if ($at->lt($earliest) || $at->gt($latest)) {
            throw new DispatchRefused('schedule_out_of_range', sprintf(
                'Scheduled rides can be booked from %d minutes to %d days ahead.', $cfg['min_lead_minutes'], $cfg['max_lead_days'],
            ));
        }
    }

    /**
     * "awaiting_driver" until a driver is assigned, then "driver_confirmed".
     */
    public static function phase(Ride $ride): ?string
    {
        if ($ride->scheduled_for === null) {
            return null;
        }

        return $ride->statusEnum()->isAwaitingDriver() ? 'awaiting_driver' : 'driver_confirmed';
    }

    /**
     * The scheduler's minute: reminders, allocation, missed slots.
     *
     * @return array{reminded: int, activated: int, requote_required: int, expired: int}
     */
    public function run(): array
    {
        $stats = ['reminded' => 0, 'activated' => 0, 'requote_required' => 0, 'expired' => 0];
        $cfg = config('ehail_dispatch.scheduled');

        Ride::where('status', RideStatus::Scheduled->value)->whereNull('reminder_sent_at')
            ->where('scheduled_for', '<=', now()->addMinutes((int) $cfg['reminder_lead_minutes']))
            ->pluck('id')->each(function ($id) use (&$stats) {
                if (Ride::whereKey($id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()]) === 1) {
                    $this->machine->emit(new ScheduledRideReminderDue($id, null, RideActor::System->value, now(), 'scheduled_reminder'));
                    $stats['reminded']++;
                }
            });

        Ride::where('status', RideStatus::Scheduled->value)
            ->where('scheduled_for', '<', now()->subMinutes((int) $cfg['grace_minutes']))
            ->pluck('id')->each(function ($id) use (&$stats) {
                try {
                    $this->machine->transition(Ride::findOrFail($id), RideStatus::Expired, RideActor::System, null,
                        new TransitionOptions(key: 'schedule:missed', reason: 'scheduled_slot_missed'));
                    $stats['expired']++;
                } catch (InvalidRideTransition) {
                }
            });

        Ride::where('status', RideStatus::Scheduled->value)
            ->whereNull('requote_required_at')
            ->where('scheduled_for', '<=', now()->addMinutes((int) $cfg['allocation_lead_minutes']))
            ->orderBy('scheduled_for')
            ->pluck('id')->each(function ($id) use (&$stats) {
                $outcome = $this->allocate(Ride::findOrFail($id));

                if ($outcome !== 'waiting') {
                    $stats[$outcome]++;
                }
            });

        return $stats;
    }

    /**
     * @return 'activated'|'requote_required'|'waiting'
     */
    public function allocate(Ride $ride): string
    {
        $fresh = $this->quotes->requote($ride);
        $limit = (int) config('ehail_dispatch.scheduled.price_guarantee_percent');
        $booked = (int) $ride->quoted_total_minor;

        if ($fresh !== null && $booked > 0 && abs($fresh->totalMinor - $booked) * 100 > $limit * $booked) {
            if (Ride::whereKey($ride->id)->whereNull('requote_required_at')->update([
                'requote_required_at' => now(), 'requote_quote_id' => $fresh->id, 'requote_total_minor' => $fresh->totalMinor,
                'version' => FeedVersion::next((int) $ride->version),
            ]) === 1) {
                $this->timeline->append($ride, 'requote_required', RideActor::System, null, 'outside_price_guarantee', [
                    'booked_total_minor' => $booked, 'new_total_minor' => $fresh->totalMinor, 'guarantee_percent' => $limit,
                ]);
                $this->machine->emit(new ScheduledRideRequoteRequired($ride->id, null, RideActor::System->value, now(), 'outside_price_guarantee'));
            }

            return 'requote_required';
        }

        if (Ride::where('active_passenger_id', $ride->passenger_id)->exists()) {
            // The passenger is on another ride right now; try again next minute until the slot is missed.
            return 'waiting';
        }

        try {
            $ride = $this->machine->transition($ride, RideStatus::Requested, RideActor::System, null, new TransitionOptions(
                key: 'schedule:activate', reason: $fresh === null ? 'booked_fare_honoured_no_requote' : 'booked_fare_honoured',
                context: ['booked_total_minor' => $booked, 'requote_total_minor' => $fresh?->totalMinor],
            ));
        } catch (InvalidRideTransition) {
            return 'waiting';
        }

        $this->dispatcher->request($ride);

        return 'activated';
    }

    /**
     * The passenger accepts the new fare offered at allocation time; dispatch starts.
     */
    public function acceptRequote(Ride $ride, User $passenger): Ride
    {
        if ($ride->passenger_id !== $passenger->id || $ride->statusEnum() !== RideStatus::Scheduled || $ride->requote_required_at === null) {
            throw new DispatchRefused('no_requote_pending', 'There is no new fare waiting for your answer on this ride.');
        }

        $fresh = $this->quotes->findForPassenger((string) $ride->requote_quote_id, $passenger);

        if ($fresh === null) {
            // The offered quote lapsed: clear it so the next scheduler pass prices the ride again.
            $ride->forceFill(['requote_required_at' => null, 'requote_quote_id' => null, 'requote_total_minor' => null])->save();

            throw new DispatchRefused('quote_invalid', 'The new fare has expired. It will be offered again shortly.', 422);
        }

        DB::transaction(function () use ($ride, $fresh, $passenger) {
            $ride->forceFill([
                'fare_quote_id' => $fresh->id,
                'quote_snapshot' => $fresh->snapshot(),
                'quoted_total_minor' => $fresh->totalMinor,
                'estimated_fare' => $fresh->totalMinor / 100,
                'requote_required_at' => null, 'requote_quote_id' => null, 'requote_total_minor' => null,
            ])->save();

            // The pricing snapshot follows the accepted quote (D-701).
            $this->quotes->bind($fresh, $passenger, $ride);
        });

        $this->timeline->append($ride, 'requote_accepted', RideActor::Passenger, $passenger->id, 'new_fare_accepted', [
            'total_minor' => $ride->quoted_total_minor,
        ]);

        $ride = $this->machine->transition($ride, RideStatus::Requested, RideActor::System, null,
            new TransitionOptions(key: 'schedule:activate', reason: 'requote_accepted', force: true));

        return $this->dispatcher->request($ride);
    }

    public function reschedule(Ride $ride, User $passenger, CarbonInterface $to): Ride
    {
        $freeUntil = (int) config('ehail_dispatch.scheduled.free_change_until_minutes');

        if ($ride->passenger_id !== $passenger->id || $ride->statusEnum() !== RideStatus::Scheduled) {
            throw new DispatchRefused('not_reschedulable', 'Only a scheduled ride that has not started dispatch can be moved.');
        }

        if ($ride->scheduled_for->copy()->subMinutes($freeUntil)->lte(now())) {
            throw new DispatchRefused('reschedule_too_late', "Rides can be moved up to {$freeUntil} minutes before pickup. Cancel and book again instead.");
        }

        self::assertBookable($to);
        $from = $ride->scheduled_for;

        $ride->forceFill(['scheduled_for' => $to, 'reminder_sent_at' => null])->save();
        $this->timeline->append($ride, 'rescheduled', RideActor::Passenger, $passenger->id, 'passenger_rescheduled', [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
        ]);

        return $ride;
    }
}
