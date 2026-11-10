<?php

namespace App\Domain\Dispatch\Data;

final class EtaEstimate
{
    public const ROAD = 'road';

    public const STRAIGHT_LINE = 'straight_line_estimate';

    public function __construct(
        public readonly int $seconds,
        public readonly int $distanceM,
        public readonly string $method,
    ) {}
}
