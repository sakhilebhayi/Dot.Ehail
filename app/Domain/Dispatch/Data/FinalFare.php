<?php

namespace App\Domain\Dispatch\Data;

final class FinalFare
{
    public function __construct(
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly string $reasonCode,
    ) {}
}
