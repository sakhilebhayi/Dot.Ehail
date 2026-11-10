<?php

namespace App\Domain\Rides;

use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideNoticeNotification;
use App\Services\Notify\EhailNotifyEvents;

/**
 * The in-app notices and audit rows each transition produced before R-46
 * (kept so passengers and drivers are told exactly what they were told
 * before), run inside the transition's transaction so they roll back with
 * it. Push/SMS/email delivery belongs to the wave-2 comms domain, which
 * listens to the domain events instead.
 */
class RideNotices
{
    public function afterTransition(Ride $ride, RideStatus $from, RideStatus $to, RideActor $actor, ?User $user, TransitionOptions $options): void
    {
        $reasonLabel = $options->reason !== null ? CancelReason::tryFrom($options->reason)?->label() : null;

        match (true) {
            $to === RideStatus::DriverAssigned => app(EhailNotifyEvents::class)->rideAccepted($ride->load(['passenger', 'driver'])),

            $to === RideStatus::CancelledByPassenger => $ride->driver_id !== null
                ? $this->notify($ride->driver, $ride, 'ride_cancelled', 'Passenger cancelled',
                    "Ride #{$ride->id} was cancelled by the passenger.", 'passenger', $reasonLabel)
                : null,

            $to === RideStatus::CancelledByOperator => $this->operatorCancelled($ride, $from, $user, $options),

            $to === RideStatus::Searching && $actor === RideActor::Driver => $this->driverLeft($ride, $from, $user, $options, $reasonLabel, false),
            $to === RideStatus::CancelledByDriver => $this->driverLeft($ride, $from, $user, $options, $reasonLabel, true),

            $to === RideStatus::Searching && $actor === RideActor::Operator => $this->operatorReassigned($ride, $from, $user, $options),

            $to === RideStatus::Expired => $this->notify($ride->passenger, $ride, 'ride_expired', 'No driver found',
                "Ride #{$ride->id} expired: no driver accepted within ".(int) config('ehail.ride_expiry_minutes').' minutes. You can request again.'),

            $to === RideStatus::NoShow => $this->notify($ride->passenger, $ride, 'ride_no_show', 'Marked as a no-show',
                "Your driver waited at the pickup point for ride #{$ride->id} and recorded a no-show."),

            $to === RideStatus::Completed && $actor === RideActor::Operator => AuditLog::record('ride.fare_entered_by_operator', $user, $ride, [
                'previous_status' => $from->value,
                'estimated_fare' => $ride->estimated_fare,
                'final_fare' => $ride->final_fare,
                'reason' => $options->note,
            ]),

            $to === RideStatus::NeedsReview && $actor === RideActor::Operator => AuditLog::record('ride.flagged_for_review', $user, $ride, [
                'previous_status' => $from->value,
                'reason' => $options->note,
            ]),

            $from === RideStatus::NeedsReview && $to === RideStatus::Searching => null,

            default => null,
        };
    }

    private function operatorCancelled(Ride $ride, RideStatus $from, ?User $operator, TransitionOptions $options): void
    {
        AuditLog::record('ride.operator_cancelled', $operator, $ride, [
            'previous_status' => $from->value,
            'reason' => $options->note,
        ]);

        foreach (array_filter([$ride->passenger, $ride->driver]) as $party) {
            $this->notify($party, $ride, 'ride_cancelled', 'Ride cancelled by an operator',
                "Ride #{$ride->id} was cancelled by a platform operator after review.",
                'operator', 'Cancelled by a platform operator after review');
        }
    }

    private function driverLeft(Ride $ride, RideStatus $from, ?User $driver, TransitionOptions $options, ?string $reasonLabel, bool $final): void
    {
        AuditLog::record('ride.driver_cancelled', $driver, $ride, [
            'reason' => $options->reason,
            'note' => $options->note,
            'previous_status' => $from->value,
            'reassignment_count' => $ride->reassignment_count,
            'outcome' => $final ? 'cancelled_by_driver' : 'requeued',
        ]);

        // Same notice (and Dot.Notify ride.expired email) the passenger got before v2,
        // when this outcome was stored as 'expired'.
        $final
            ? $this->notify($ride->passenger, $ride, 'ride_expired', 'No driver found',
                "Ride #{$ride->id} ended: your drivers kept cancelling and no other driver was assigned. You can request again.")
            : $this->notify($ride->passenger, $ride, 'ride_requeued', 'Your driver cancelled',
                "Your driver for ride #{$ride->id} cancelled. We are looking for another driver.",
                'driver', $reasonLabel);
    }

    private function operatorReassigned(Ride $ride, RideStatus $from, ?User $operator, TransitionOptions $options): void
    {
        AuditLog::record('ride.operator_reassigned', $operator, $ride, [
            'previous_status' => $from->value,
            'reason' => $options->note,
            'reassignment_count' => $ride->reassignment_count,
        ]);

        $this->notify($ride->passenger, $ride, 'ride_requeued', 'Finding you another driver',
            "An operator moved ride #{$ride->id} to a new driver search.");
    }

    private function notify(?User $user, Ride $ride, string $type, string $title, string $message, ?string $cancelledByRole = null, ?string $reason = null): void
    {
        $user?->notify(new RideNoticeNotification($ride, $type, $title, $message, $cancelledByRole, $reason));
    }
}
