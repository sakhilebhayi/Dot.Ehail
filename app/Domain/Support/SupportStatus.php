<?php

namespace App\Domain\Support;

/**
 *   new -> triaged | in_progress | resolved | closed
 *   triaged -> in_progress | waiting_on_user | resolved
 *   in_progress -> waiting_on_user | resolved
 *   waiting_on_user -> in_progress (the user replied or an agent resumed) | resolved | closed
 *   resolved -> closed | in_progress (reopened)
 *   closed -> (final)
 */
enum SupportStatus: string
{
    case New = 'new';
    case Triaged = 'triaged';
    case InProgress = 'in_progress';
    case WaitingOnUser = 'waiting_on_user';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Triaged, self::InProgress, self::Resolved, self::Closed],
            self::Triaged => [self::InProgress, self::WaitingOnUser, self::Resolved],
            self::InProgress => [self::WaitingOnUser, self::Resolved],
            self::WaitingOnUser => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }
}
