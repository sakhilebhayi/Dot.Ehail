<?php

namespace App\Domain\Support;

enum SupportPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function firstResponseMinutes(): int
    {
        return (int) config('ehail_safety.support.sla.'.$this->value.'.first_response', 480);
    }

    public function resolutionMinutes(): int
    {
        return (int) config('ehail_safety.support.sla.'.$this->value.'.resolution', 4320);
    }

    public function higher(): self
    {
        return match ($this) {
            self::Low => self::Normal,
            self::Normal => self::High,
            self::High, self::Urgent => self::Urgent,
        };
    }
}
