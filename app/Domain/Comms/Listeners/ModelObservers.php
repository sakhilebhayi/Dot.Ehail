<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Templates;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\Models\Receipt;
use App\Models\Ride;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications for facts that other domains record without a domain event: a scheduled booking,
 * a ride offer, a receipt, a payout paid or failed, an incentive earned. Registered as model
 * listeners by EhailCommsServiceProvider (the owning domains' files are not touched) and run only
 * after the surrounding transaction commits, so a rolled-back change never notifies anyone.
 */
final class ModelObservers
{
    public function __construct(private readonly NotificationRouter $router, private readonly Templates $templates) {}

    public function rideCreated(Ride $ride): void
    {
        $this->safely('scheduled_booked', function () use ($ride) {
            if ($ride->status === 'scheduled' && $ride->scheduled_for !== null) {
                $this->sendIf($this->templates->scheduledBooked($ride->loadMissing('passenger')));
            }
        });
    }

    public function offerCreated(RideOffer $offer): void
    {
        $this->safely('offer_received', function () use ($offer) {
            if ($offer->status !== RideOffer::PENDING || $offer->driver_id === null) {
                return;
            }

            // In-app only, and only while the driver is online (an offer to someone who has gone
            // offline is withdrawn by dispatch anyway).
            $state = DriverAvailability::where('user_id', $offer->driver_id)->value('state');

            if (! in_array($state, [DriverAvailability::ONLINE, DriverAvailability::ON_TRIP], true) || $offer->driver === null) {
                return;
            }

            $this->router->send($this->templates->offerReceived($offer->driver, $offer->id, (int) $offer->ride_id, $offer->pickup_eta_s, $offer->expires_at));
        });
    }

    public function receiptCreated(Receipt $receipt): void
    {
        $this->safely('payment_receipt', fn () => $this->sendIf($this->templates->receipt($receipt)));
    }

    public function payoutUpdated(Payout $payout): void
    {
        $this->safely('payout', function () use ($payout) {
            if ($payout->wasChanged('status') && in_array($payout->status, [Payout::PAID, Payout::FAILED], true)) {
                $this->sendIf($this->templates->payout($payout));
            }
        });
    }

    public function incentiveSaved(IncentiveProgress $progress): void
    {
        $this->safely('incentive_earned', function () use ($progress) {
            if ($progress->status === 'earned' && ($progress->wasRecentlyCreated || $progress->wasChanged('status'))) {
                $this->sendIf($this->templates->incentiveEarned($progress));
            }
        });
    }

    private function sendIf(?CommsMessage $message): void
    {
        if ($message !== null) {
            $this->router->send($message);
        }
    }

    private function safely(string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Comms could not prepare a notification.', ['what' => $what, 'error' => class_basename($e)]);
        }
    }
}
