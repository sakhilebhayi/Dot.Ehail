<?php

namespace App\Domain\Comms;

use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Money;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Ui\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * Plain-language text for every comms-originated notification. Minimisation: a driver's first name
 * and the car's colour, make and model; never a surname, phone number, plate, ID or coordinates
 * (the ride screen shows the plate to the passenger). Times are shown in the market's time zone.
 *
 * Each method returns null when there is nobody to tell.
 */
final class Templates
{
    public function driverAssigned(Ride $ride, ?int $actorId): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->driver_id === null) {
            return null;
        }

        $driver = $this->firstName($ride->driver);
        $car = $this->vehicle($ride);
        $assignment = (int) $ride->reassignment_count;

        if ($ride->scheduled_for !== null) {
            return new CommsMessage($ride->passenger, 'scheduled_driver_confirmed', "ride:{$ride->id}:driver_assigned:{$assignment}",
                'Driver confirmed for your scheduled ride',
                trim("{$driver} will pick you up for your ".$this->time($ride->scheduled_for).' ride'.($car ? " in a {$car}" : '').'.'),
                $this->rideUrl($ride), "ride-{$ride->id}", $actorId, data: ['ride_id' => $ride->id]);
        }

        return new CommsMessage($ride->passenger, 'driver_assigned', "ride:{$ride->id}:driver_assigned:{$assignment}",
            'Your driver is on the way',
            "{$driver} accepted your ride".($car ? " and is coming in a {$car}" : '').'. Check the number plate in the app before you get in.',
            $this->rideUrl($ride), "ride-{$ride->id}", $actorId, data: ['ride_id' => $ride->id]);
    }

    public function driverApproaching(Ride $ride, int $distanceM): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->driver_id === null) {
            return null;
        }

        $rounded = max(100, (int) (round($distanceM / 100) * 100));

        return new CommsMessage($ride->passenger, 'driver_approaching', "ride:{$ride->id}:driver_approaching:".(int) $ride->reassignment_count,
            'Your driver is nearly there',
            $this->firstName($ride->driver)." is about {$rounded} m from the pickup point. Start making your way there.",
            $this->rideUrl($ride), "ride-{$ride->id}", data: ['ride_id' => $ride->id]);
    }

    public function driverArrived(Ride $ride, ?int $actorId): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->driver_id === null) {
            return null;
        }

        $car = $this->vehicle($ride);

        return new CommsMessage($ride->passenger, 'driver_arrived', "ride:{$ride->id}:driver_arrived:".(int) $ride->reassignment_count,
            'Your driver has arrived',
            $this->firstName($ride->driver).' is waiting at the pickup point'.($car ? " in a {$car}" : '').'. Match the number plate in the app, then give the driver your PIN once you are in the car.',
            $this->rideUrl($ride), "ride-{$ride->id}", $actorId, data: ['ride_id' => $ride->id]);
    }

    public function tripStarted(Ride $ride, ?int $actorId): ?CommsMessage
    {
        if ($ride->passenger === null) {
            return null;
        }

        return new CommsMessage($ride->passenger, 'trip_started', "ride:{$ride->id}:trip_started",
            'Trip started',
            'You are on your way'.($ride->dropoff_address ? ' to '.$this->shortPlace($ride->dropoff_address) : '').'. You can share your trip from the Safety screen.',
            $this->rideUrl($ride), "ride-{$ride->id}", $actorId, data: ['ride_id' => $ride->id]);
    }

    public function scheduledBooked(Ride $ride): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->scheduled_for === null) {
            return null;
        }

        return new CommsMessage($ride->passenger, 'scheduled_booked', "ride:{$ride->id}:scheduled_booked",
            'Ride booked, awaiting a driver',
            'Your ride on '.$this->dateTime($ride->scheduled_for).' is booked. We look for a driver shortly before pickup, so it shows "awaiting driver" until one accepts. We will tell you when a driver is confirmed.',
            $this->rideUrl($ride), "ride-{$ride->id}", $ride->passenger_id, data: ['ride_id' => $ride->id]);
    }

    public function scheduledReminder(Ride $ride): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->scheduled_for === null) {
            return null;
        }

        return new CommsMessage($ride->passenger, 'scheduled_reminder', "ride:{$ride->id}:scheduled_reminder:".$ride->scheduled_for->timestamp,
            'Your ride is coming up',
            'Pickup at '.$this->time($ride->scheduled_for).($ride->pickup_address ? ' from '.$this->shortPlace($ride->pickup_address) : '').'. Still awaiting a driver: we start looking shortly before pickup.',
            $this->rideUrl($ride), "ride-{$ride->id}", data: ['ride_id' => $ride->id]);
    }

    public function requoteRequired(Ride $ride): ?CommsMessage
    {
        if ($ride->passenger === null || $ride->scheduled_for === null) {
            return null;
        }

        $currency = (string) ($ride->currency ?: 'ZAR');
        $new = $ride->requote_total_minor !== null ? Money::format((int) $ride->requote_total_minor, $currency) : null;
        $old = $ride->quoted_total_minor !== null ? Money::format((int) $ride->quoted_total_minor, $currency) : null;

        return new CommsMessage($ride->passenger, 'scheduled_requote_required', "ride:{$ride->id}:requote:".($ride->requote_quote_id ?? 'x'),
            'New price needs your OK',
            'The price for your '.$this->time($ride->scheduled_for).' ride is now '.($new ?? 'different')
                .($old ? " (booked at {$old})" : '').'. Accept it in the app before pickup, or the booking will lapse and no driver will be sent.',
            $this->rideUrl($ride), "ride-{$ride->id}", data: ['ride_id' => $ride->id]);
    }

    public function receipt(Receipt $receipt): ?CommsMessage
    {
        $passenger = User::find($receipt->passenger_id);

        if ($passenger === null) {
            return null;
        }

        $amount = Money::format((int) $receipt->total_minor, (string) $receipt->currency);
        $what = $receipt->kind === 'cancellation_fee' ? 'the cancellation fee' : 'your trip';

        return new CommsMessage($passenger, 'payment_receipt', "receipt:{$receipt->id}",
            'Payment received',
            "{$amount} paid for {$what}. Receipt {$receipt->number} is ready.",
            Route::has('money.receipts.show') ? route('money.receipts.show', $receipt, false) : null,
            $receipt->ride_id ? "ride-{$receipt->ride_id}" : null,
            data: ['receipt_id' => $receipt->id, 'ride_id' => $receipt->ride_id]);
    }

    public function payout(Payout $payout): ?CommsMessage
    {
        $payee = User::find($payout->payee_user_id);

        if ($payee === null || ! in_array($payout->status, [Payout::PAID, Payout::FAILED], true)) {
            return null;
        }

        $amount = Money::format((int) $payout->amount_minor, (string) $payout->currency);
        $url = Route::has('money.driver.payout') ? route('money.driver.payout', $payout, false) : null;

        return $payout->status === Payout::PAID
            ? new CommsMessage($payee, 'payout_paid', "payout:{$payout->id}:paid",
                'Payout paid',
                "Your payout of {$amount} (reference {$payout->reference}) has been paid out to your bank account. Banks can take up to a business day to show it.",
                $url, null, data: ['payout_id' => $payout->id])
            : new CommsMessage($payee, 'payout_failed', "payout:{$payout->id}:failed:".substr(sha1((string) $payout->failure_reason), 0, 8),
                'Payout failed',
                "Your payout of {$amount} (reference {$payout->reference}) could not be paid".($payout->failure_reason ? ': '.mb_substr($payout->failure_reason, 0, 160) : '').'. Check your bank details under Earnings; the money stays owed to you.',
                $url, null, data: ['payout_id' => $payout->id]);
    }

    public function incentiveEarned(IncentiveProgress $progress): ?CommsMessage
    {
        $driver = User::find($progress->driver_id);
        $program = $progress->program;

        if ($driver === null || $program === null) {
            return null;
        }

        return new CommsMessage($driver, 'incentive_earned', "incentive:{$progress->id}:earned",
            'Incentive earned',
            'You earned '.Money::format((int) $program->reward_minor, (string) $program->currency).' from "'.mb_substr((string) $program->name, 0, 80).'". It is added to your next payout.',
            Route::has('money.driver.incentives') ? route('money.driver.incentives', [], false) : null,
            null, data: ['incentive_progress_id' => $progress->id]);
    }

    public function offerReceived(User $driver, int $offerId, int $rideId, ?int $pickupEtaS, ?CarbonInterface $expiresAt): CommsMessage
    {
        $pickup = $pickupEtaS !== null ? 'Pickup about '.max(1, (int) round($pickupEtaS / 60)).' min away. ' : '';

        return new CommsMessage($driver, 'offer_received', "offer:{$offerId}",
            'New ride offer',
            $pickup.'Answer on the Drive screen'.($expiresAt ? ' before '.$this->localTime($expiresAt, 'H:i:s') : '').'.',
            Route::has('drive.home') ? route('drive.home', [], false) : null,
            "offer-{$driver->id}", replaceUnread: true,
            data: ['ride_id' => $rideId, 'offer_id' => $offerId, 'expires_at' => $expiresAt?->toIso8601String()]);
    }

    private function rideUrl(Ride $ride): ?string
    {
        return Route::has('rides.show') ? route('rides.show', $ride, false) : null;
    }

    private function firstName(?User $user): string
    {
        $first = trim(strtok((string) $user?->name, ' ') ?: '');

        return $first !== '' ? $first : 'Your driver';
    }

    private function vehicle(Ride $ride): ?string
    {
        $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;

        if ($vehicle === null) {
            return null;
        }

        $text = trim(implode(' ', array_filter([mb_strtolower((string) $vehicle->color), $vehicle->make, $vehicle->model])));

        return $text !== '' ? $text : null;
    }

    private function shortPlace(string $address): string
    {
        return mb_substr(trim(explode(',', $address)[0]), 0, 60);
    }

    private function time(CarbonInterface $at): string
    {
        return $this->localTime($at, 'H:i');
    }

    private function dateTime(CarbonInterface $at): string
    {
        return $this->localTime($at, 'D j M \a\t H:i');
    }

    private function localTime(CarbonInterface $at, string $format): string
    {
        return $at->copy()->setTimezone(LocalTime::timezone())->format($format);
    }
}
