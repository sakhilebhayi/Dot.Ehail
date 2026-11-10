<?php

namespace App\Domain\Safety;

enum IncidentKind: string
{
    case Sos = 'sos';
    case Accident = 'accident';
    case Harassment = 'harassment';
    case Deviation = 'deviation';
    case Other = 'other';

    public function defaultSeverity(): IncidentSeverity
    {
        return match ($this) {
            self::Sos => IncidentSeverity::Critical,
            self::Accident, self::Harassment => IncidentSeverity::High,
            self::Deviation => IncidentSeverity::High,
            self::Other => IncidentSeverity::Medium,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sos => 'Emergency (SOS)',
            self::Accident => 'Accident',
            self::Harassment => 'Harassment',
            self::Deviation => 'Unexpected route or stop',
            self::Other => 'Other safety concern',
        };
    }
}
