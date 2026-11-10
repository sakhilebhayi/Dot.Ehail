<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Templates;
use App\Domain\Rides\Events\DriverArrived;
use App\Domain\Rides\Events\DriverAssigned;
use App\Domain\Rides\Events\RideDomainEvent;
use App\Domain\Rides\Events\ScheduledRideReminderDue;
use App\Domain\Rides\Events\ScheduledRideRequoteRequired;
use App\Domain\Rides\Events\TripStarted;
use App\Models\Ride;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ride lifecycle domain events (dispatched after commit) to passenger notifications. Events that
 * already produce an in-app notice elsewhere are not repeated here: cancellations, expiry,
 * requeue and no-show (RideNotices), completion (RideObserver -> RideCompletedNotification);
 * comms fans those out from their in-app record instead (SourceNotificationListener).
 * DriverArriving is not announced separately: "on the way" was said at assignment, and
 * "nearly there" comes from ApproachWatcher.
 */
final class RideEventsListener
{
    public function __construct(private readonly NotificationRouter $router, private readonly Templates $templates) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(DriverAssigned::class, [self::class, 'handle']);
        $events->listen(DriverArrived::class, [self::class, 'handle']);
        $events->listen(TripStarted::class, [self::class, 'handle']);
        $events->listen(ScheduledRideReminderDue::class, [self::class, 'handle']);
        $events->listen(ScheduledRideRequoteRequired::class, [self::class, 'handle']);
    }

    /** The driver has a ride now: their offer notices are answered, so they stop counting as unread. */
    private function settleOffers(int $driverId): void
    {
        $ids = NotificationDelivery::where('user_id', $driverId)->where('collapse_key', 'offer-'.$driverId)
            ->where('channel', NotificationDelivery::IN_APP)->whereNotNull('notification_id')->pluck('notification_id');

        if ($ids->isNotEmpty()) {
            DatabaseNotification::query()->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now()]);
        }
    }

    public function handle(RideDomainEvent $event): void
    {
        try {
            $ride = Ride::with(['passenger', 'driver'])->find($event->rideId);

            if ($ride === null) {
                return;
            }

            $message = match (true) {
                $event instanceof DriverAssigned => $this->templates->driverAssigned($ride, $event->actorId),
                $event instanceof DriverArrived => $this->templates->driverArrived($ride, $event->actorId),
                $event instanceof TripStarted => $this->templates->tripStarted($ride, $event->actorId),
                $event instanceof ScheduledRideReminderDue => $this->templates->scheduledReminder($ride),
                $event instanceof ScheduledRideRequoteRequired => $this->templates->requoteRequired($ride),
                default => null,
            };

            if ($message !== null) {
                $this->router->send($message);
            }

            if ($event instanceof DriverAssigned && $ride->driver_id !== null) {
                $this->settleOffers((int) $ride->driver_id);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not handle a ride event.', ['event' => $event::name(), 'ride_id' => $event->rideId, 'error' => class_basename($e)]);
        }
    }
}
