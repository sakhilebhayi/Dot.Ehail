<?php

namespace App\Domain\Ops\Support;

use App\Domain\Rides\Models\RideEvent;
use App\Enums\RideStatus;

/** Plain words for a ride_events row on the operations timeline: what happened, the detail, and its kind of stop. */
final class TimelineWords
{
    /** @return array{what: string, detail: string, kind: string} */
    public static function describe(RideEvent $event): array
    {
        $to = $event->to_status ? (RideStatus::tryFrom($event->to_status)?->label() ?? $event->to_status) : null;
        $actor = match ($event->actor_type) {
            'passenger' => 'passenger',
            'driver' => 'driver',
            'operator' => 'operator',
            'system' => 'system',
            default => (string) $event->actor_type,
        };
        $context = is_array($event->context) ? $event->context : [];
        $note = $context['note'] ?? $context['reason'] ?? null;

        $what = match ($event->type) {
            'transition' => $to ?? 'State changed',
            'created' => 'Ride created',
            'offer_sent' => 'Offer sent'.(isset($context['driver_id']) ? ' to driver '.$context['driver_id'] : ''),
            'offer_declined' => 'Offer declined'.(isset($context['driver_id']) ? ' by driver '.$context['driver_id'] : ''),
            'offer_expired' => 'Offer timed out'.(isset($context['driver_id']) ? ' (driver '.$context['driver_id'].')' : ''),
            'pin_rejected' => 'Wrong trip PIN entered',
            'requote_required' => 'New fare needed before dispatch',
            'requote_accepted' => 'Passenger accepted the new fare',
            'rescheduled' => 'Pickup time changed',
            'status_migrated' => 'Status renamed in the R-46 migration'.($to ? ' to '.$to : ''),
            default => ucfirst(str_replace('_', ' ', (string) $event->type)),
        };

        $detailParts = array_filter([
            'by '.$actor.($event->actor_id ? ' '.$event->actor_id : ''),
            $event->reason_code ? str_replace('_', ' ', (string) $event->reason_code) : null,
            is_string($note) && $note !== '' ? '"'.$note.'"' : null,
        ]);

        $kind = match (true) {
            $event->type !== 'transition' => 'side',
            in_array($event->to_status, ['cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'failed', 'needs_review', 'expired', 'no_show'], true) => 'stop',
            default => 'main',
        };

        return ['what' => $what, 'detail' => implode(' · ', $detailParts), 'kind' => $kind];
    }
}
