<?php

namespace App\Domain\Passenger;

/**
 * The live ride page's words for the first paint, the same as resources/js/passenger/status.js (the feed client
 * keeps them current). Input: the ride and driver_position sections of the dispatch passenger feed.
 */
final class RideText
{
    public const STAGES = ['Finding a driver', 'Driver on the way', 'At pickup', 'On the trip', 'Arrived'];

    /**
     * @param  array<string, mixed>  $ride
     * @param  array<string, mixed>|null  $position
     * @return array{title: string, sub: string}
     */
    public static function for(array $ride, ?array $position = null): array
    {
        $name = $ride['driver']['first_name'] ?? null ?: 'Your driver';
        $eta = isset($position['eta_s']) && is_numeric($position['eta_s']) ? max(1, (int) round($position['eta_s'] / 60)) : null;
        $estimate = isset($position['eta_method']) && $position['eta_method'] !== 'road' ? 'about ' : '';
        $place = trim(explode(',', (string) ($ride['dropoff']['address'] ?? ''))[0]) ?: 'your destination';

        return match ($ride['status'] ?? null) {
            'scheduled' => ['title' => 'Ride booked', 'sub' => ($ride['schedule_phase'] ?? null) === 'driver_confirmed' ? 'A driver has confirmed your ride.' : 'Awaiting driver. We start looking shortly before your pickup time.'],
            'requested', 'searching' => ['title' => 'Finding a driver', 'sub' => 'We offer your ride to nearby drivers one at a time. Cancelling now is free.'],
            'driver_assigned', 'driver_arriving' => ['title' => "{$name} is on the way", 'sub' => $eta ? "Arrives in {$estimate}{$eta} min." : 'Arrival time shows once the driver’s phone reports a position.'],
            'driver_arrived' => ['title' => "{$name} is here", 'sub' => 'Meet your driver at the pickup. Check the car and registration first.'],
            'in_progress' => ['title' => "On the way to {$place}", 'sub' => $eta ? "Arriving in {$estimate}{$eta} min." : 'Enjoy the ride. Your trip is being followed.'],
            'completed' => ['title' => 'You have arrived', 'sub' => 'Thank you for riding with Dot.Ehail.'],
            'cancelled_by_passenger' => ['title' => 'You cancelled this ride', 'sub' => ($ride['fare']['cancellation_fee_minor'] ?? null) ? 'A cancellation fee applies under the fare rules.' : 'No fee was charged.'],
            'cancelled_by_driver' => ['title' => 'The driver cancelled', 'sub' => 'We could not find another driver for this ride. You are not charged.'],
            'cancelled_by_operator' => ['title' => 'Cancelled by Dot.Ehail', 'sub' => 'Our operations team cancelled this ride. Ask support if you need to know why.'],
            'expired' => ['title' => 'No driver found', 'sub' => 'No driver accepted in time. You are not charged.'],
            'no_show' => ['title' => 'Marked as a no-show', 'sub' => 'The driver waited at the pickup for the free waiting time. A no-show fee may apply under the fare rules.'],
            'failed' => ['title' => 'This ride could not go ahead', 'sub' => 'Something went wrong on our side. You are not charged for a trip that did not happen.'],
            'needs_review' => ['title' => 'Under review', 'sub' => 'Our operations team is checking this ride. We will tell you the outcome.'],
            default => ['title' => (string) ($ride['status_label'] ?? 'Your ride'), 'sub' => ''],
        };
    }

    public static function stageIndex(string $status): int
    {
        return match ($status) {
            'requested', 'searching' => 0,
            'driver_assigned', 'driver_arriving' => 1,
            'driver_arrived' => 2,
            'in_progress' => 3,
            'completed' => 4,
            default => -1,
        };
    }
}
