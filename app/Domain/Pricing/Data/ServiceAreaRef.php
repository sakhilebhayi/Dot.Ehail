<?php

namespace App\Domain\Pricing\Data;

final readonly class ServiceAreaRef
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $country,
        public string $currency,
        public string $timezone,
    ) {}
}
