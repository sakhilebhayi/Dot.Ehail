<?php

namespace App\Domain\Dispatch\Data;

final class PaymentChoice
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $reference = null,
    ) {}
}
