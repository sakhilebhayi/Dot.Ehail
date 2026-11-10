<?php

namespace App\Domain\Safety;

enum IncidentSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** Minutes an unacknowledged incident waits before it moves to the next escalation level. */
    public function escalationMinutes(): int
    {
        return (int) config('ehail_safety.escalation.minutes_by_severity.'.$this->value, 15);
    }
}
