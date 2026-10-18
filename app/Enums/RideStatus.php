<?php

namespace App\Enums;

/**
 * The single definition of a ride's lifecycle. Every state change made
 * through App\Services\Ehail\RideLifecycle is checked against
 * allowedTransitions(); nothing else decides what may follow what.
 *
 *   requested -> accepted -> en_route -> arrived -> in_progress -> completed
 *
 * with the side exits: cancelled (passenger/operator), expired (the request
 * timed out, or too many drivers dropped it), needs_review (an in-progress
 * trip nobody finished; an operator resolves it), and accepted/en_route/
 * arrived -> requested (the driver backed out and the ride re-queues).
 *
 * rides.status stays a plain string column and attribute so existing queries
 * and views keep working; use Ride::statusEnum() to get this type.
 */
enum RideStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case EnRoute = 'en_route';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NeedsReview = 'needs_review';

    /**
     * target status value => actors allowed to take that edge.
     *
     * @return array<string, list<RideActor>>
     */
    public function allowedTransitions(): array
    {
        $driver = RideActor::Driver;
        $passenger = RideActor::Passenger;
        $operator = RideActor::Operator;
        $system = RideActor::System;

        return match ($this) {
            self::Requested => [
                self::Accepted->value => [$driver],
                self::Cancelled->value => [$passenger, $operator],
                self::Expired->value => [$system],
            ],
            self::Accepted => [
                self::EnRoute->value => [$driver],
                self::Cancelled->value => [$passenger, $operator],
                self::Requested->value => [$driver],
                self::Expired->value => [$driver, $system],
            ],
            self::EnRoute => [
                self::Arrived->value => [$driver],
                self::Cancelled->value => [$passenger, $operator],
                self::Requested->value => [$driver],
                self::Expired->value => [$driver, $system],
            ],
            self::Arrived => [
                self::InProgress->value => [$driver],
                self::Cancelled->value => [$passenger, $operator],
                self::Requested->value => [$driver],
                self::Expired->value => [$driver, $system],
            ],
            self::InProgress => [
                self::Completed->value => [$driver, $operator],
                self::NeedsReview->value => [$system],
                self::Cancelled->value => [$operator],
            ],
            self::NeedsReview => [
                self::Completed->value => [$operator],
                self::Cancelled->value => [$operator],
            ],
            self::Completed, self::Cancelled, self::Expired => [],
        };
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

    /**
     * The next stage the driver moves the ride to, one step at a time.
     */
    public function nextStage(): ?self
    {
        return match ($this) {
            self::Accepted => self::EnRoute,
            self::EnRoute => self::Arrived,
            self::Arrived => self::InProgress,
            self::InProgress => self::Completed,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'Needs review',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }
}
