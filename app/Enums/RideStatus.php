<?php

namespace App\Enums;

use App\Domain\Rides\RideTransitions;

/**
 * The single definition of a ride's lifecycle (R-46 state machine v2,
 * docs/R46_ARCHITECTURE.md section 2). The edges, the actors allowed on each
 * edge, the guards and the side effects live in one table,
 * App\Domain\Rides\RideTransitions; App\Domain\Rides\RideStateMachine is the
 * only code that changes rides.status.
 *
 *   scheduled -> requested -> searching -> driver_assigned -> driver_arriving
 *     -> driver_arrived -> in_progress -> completed
 *
 * Side and terminal states: cancelled_by_passenger, cancelled_by_driver
 * (only when the reassignment policy is exhausted; otherwise a driver who
 * backs out sends the ride back to searching), cancelled_by_operator,
 * expired (no driver within the search window), no_show, failed (system
 * fault), needs_review (stuck or disputed; operators resolve it).
 *
 * The values written before v2 ('accepted', 'en_route', 'arrived',
 * 'cancelled') were rewritten by the 2026_11_10_400100 data migration. They
 * remain readable through fromStored() so audit history that quotes them
 * still resolves.
 *
 * rides.status stays a plain string column; Ride::statusEnum() returns this type.
 */
enum RideStatus: string
{
    case Scheduled = 'scheduled';
    case Requested = 'requested';
    case Searching = 'searching';
    case DriverAssigned = 'driver_assigned';
    case DriverArriving = 'driver_arriving';
    case DriverArrived = 'driver_arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CancelledByPassenger = 'cancelled_by_passenger';
    case CancelledByDriver = 'cancelled_by_driver';
    case CancelledByOperator = 'cancelled_by_operator';
    case Expired = 'expired';
    case NoShow = 'no_show';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';

    /**
     * Values stored before state machine v2 => the v2 state they became.
     * 'cancelled' rows were split by who cancelled (passenger or operator;
     * no other actor could reach it before v2); the alias below is only the
     * reading for a row that somehow escaped the migration, and operator is
     * the conservative reading (it puts the ride in front of a human).
     */
    public const LEGACY_ALIASES = [
        'accepted' => 'driver_assigned',
        'en_route' => 'driver_arriving',
        'arrived' => 'driver_arrived',
        'cancelled' => 'cancelled_by_operator',
    ];

    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::from(self::LEGACY_ALIASES[$value] ?? $value);
    }

    /**
     * target status value => actors allowed to take that edge.
     *
     * @return array<string, list<RideActor>>
     */
    public function allowedTransitions(): array
    {
        $edges = [];

        foreach (RideTransitions::from($this) as $to => $edge) {
            $edges[$to] = $edge['actors'];
        }

        return $edges;
    }

    public function canTransitionTo(self $to, ?RideActor $actor = null): bool
    {
        $allowed = $this->allowedTransitions()[$to->value] ?? null;

        if ($allowed === null) {
            return false;
        }

        return $actor === null || in_array($actor, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::CancelledByPassenger, self::CancelledByDriver, self::CancelledByOperator], true);
    }

    /**
     * Waiting for a driver: the ride exists and nobody has been assigned.
     */
    public function isAwaitingDriver(): bool
    {
        return in_array($this, [self::Scheduled, self::Requested, self::Searching], true);
    }

    /**
     * A driver holds the ride (from assignment until the trip ends).
     * needs_review is deliberately excluded: a flagged ride releases the
     * driver so an operator's pending decision does not keep them offline.
     */
    public function holdsDriver(): bool
    {
        return in_array($this, [self::DriverAssigned, self::DriverArriving, self::DriverArrived, self::InProgress], true);
    }

    /**
     * Counts towards "this passenger already has a ride going" (scheduled
     * rides in the future do not).
     */
    public function isActiveForPassenger(): bool
    {
        return in_array($this, [self::Requested, self::Searching, self::DriverAssigned, self::DriverArriving, self::DriverArrived, self::InProgress], true);
    }

    /**
     * @return list<self>
     */
    public static function driverHoldingStates(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->holdsDriver()));
    }

    /**
     * @return list<string>
     */
    public static function values(array $states): array
    {
        return array_map(fn (self $s) => $s->value, $states);
    }

    /**
     * The next stage the assigned driver moves the ride to, one step at a time.
     */
    public function nextStage(): ?self
    {
        return match ($this) {
            self::DriverAssigned => self::DriverArriving,
            self::DriverArriving => self::DriverArrived,
            self::DriverArrived => self::InProgress,
            self::InProgress => self::Completed,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Requested => 'Requested',
            self::Searching => 'Finding a driver',
            self::DriverAssigned => 'Driver assigned',
            self::DriverArriving => 'Driver on the way',
            self::DriverArrived => 'Driver arrived',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::CancelledByPassenger => 'Cancelled by passenger',
            self::CancelledByDriver => 'Cancelled by driver',
            self::CancelledByOperator => 'Cancelled by operator',
            self::Expired => 'Expired',
            self::NoShow => 'No-show',
            self::Failed => 'Failed',
            self::NeedsReview => 'Needs review',
        };
    }
}
