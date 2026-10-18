<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\RideActor;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * A ride is private to its passenger, its assigned driver, that driver's
 * fleet owner (approved fleets only) and platform operators. The ride list
 * and the dashboard apply the same rule through Ride::scopeVisibleTo().
 * The open-request claim queue is separate: it is only shown to approved,
 * online drivers (RideLifecycleController::available) and carries no
 * passenger identity.
 */
class RidePolicy
{
    use HandlesAuthorization;

    /**
     * Anyone signed in may open the ride list; it is filtered to the rides
     * they may see via Ride::visibleTo().
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ride $ride): bool
    {
        return Ride::query()->visibleTo($user)->whereKey($ride->getKey())->exists();
    }

    /**
     * Any authenticated user may request a ride as a passenger -- being a
     * driver elsewhere in the platform doesn't disqualify someone from
     * also riding, matching how real ride-hailing apps work.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * A driver may claim an open request if they have an approved, online
     * DriverProfile whose active vehicle may serve the ride's requested
     * tier (config/ehail.php vehicle_tiers: a premium ride needs a premium
     * vehicle unless the mapping says otherwise).
     */
    public function accept(User $user, Ride $ride): bool
    {
        if ($ride->status !== 'requested' || $ride->driver_id !== null) {
            return false;
        }

        // Nobody drives their own request.
        if ($user->id === $ride->passenger_id) {
            return false;
        }

        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->first();

        return $profile !== null
            && $profile->status === 'approved'
            && $profile->is_online
            && $profile->activeVehicleFor($ride->vehicle_type) !== null;
    }

    /**
     * Only the ride's assigned driver may progress it through its
     * lifecycle (en_route -> arrived -> in_progress -> completed). Which
     * stage may follow which is RideStatus's job, not this policy's.
     */
    public function advance(User $user, Ride $ride): bool
    {
        return $ride->driver_id !== null && $user->id === $ride->driver_id;
    }

    /**
     * The passenger may cancel until the trip starts. The assigned driver
     * may back out of an accepted ride until the trip starts (which sends
     * the ride back to the queue -- see RideLifecycle::cancel). Once
     * in_progress nobody on the ride may cancel: completion, or an operator's
     * review, is the only way out.
     */
    public function cancel(User $user, Ride $ride): bool
    {
        return match ($ride->actorFor($user)) {
            RideActor::Passenger => in_array($ride->status, ['requested', 'accepted', 'en_route', 'arrived'], true),
            RideActor::Driver => in_array($ride->status, ['accepted', 'en_route', 'arrived'], true),
            default => false,
        };
    }

    /**
     * Only the passenger and the driver of a completed ride may rate it (each
     * the other; RideRatings enforces once per person). An operator who is
     * neither party may read ratings but never write one.
     */
    public function rate(User $user, Ride $ride): bool
    {
        return $ride->status === 'completed'
            && $ride->driver_id !== null
            && in_array($ride->actorFor($user), [RideActor::Passenger, RideActor::Driver], true);
    }

    /**
     * Resolving a stuck trip (entering the fare by hand, or cancelling it) is
     * an operator action, and only on a trip that is under way.
     */
    public function resolve(User $user, Ride $ride): bool
    {
        return $user->isPlatformOperator()
            && in_array($ride->status, ['in_progress', 'needs_review'], true);
    }

    /**
     * Only the ride's passenger may pay its fare: not the driver, not the driver's fleet owner and
     * not an operator, whatever else they may see. Whether a payment is possible right now (completed,
     * unpaid, Paystack enabled) is RidePayments' job and is reported as a message, not a 403.
     */
    public function pay(User $user, Ride $ride): bool
    {
        return $user->id === $ride->passenger_id;
    }

    /**
     * An operator may write off a completed ride's fare while it is still owed.
     */
    public function waive(User $user, Ride $ride): bool
    {
        return $user->isPlatformOperator()
            && $ride->status === 'completed'
            && in_array($ride->paymentStatusEnum(), PaymentStatus::collectable(), true);
    }
}
