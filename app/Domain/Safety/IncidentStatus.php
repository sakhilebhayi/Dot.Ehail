<?php

namespace App\Domain\Safety;

/**
 *   open -> acknowledged | escalated | resolved
 *   escalated -> acknowledged | escalated (next level) | resolved
 *   acknowledged -> resolved | escalated (an operator escalates by hand)
 *   resolved -> closed | acknowledged (reopened)
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Escalated, self::Resolved],
            self::Escalated => [self::Acknowledged, self::Escalated, self::Resolved],
            self::Acknowledged => [self::Resolved, self::Escalated],
            self::Resolved => [self::Closed, self::Acknowledged],
            self::Closed => [],
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Acknowledged, self::Escalated], true);
    }
}
