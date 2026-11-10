<?php

namespace App\Domain\Pricing\Promotions;

use App\Domain\Pricing\Engine\DiscountTerms;
use App\Domain\Pricing\Models\Promotion;

final readonly class PromotionDecision
{
    public function __construct(public ?Promotion $promotion, public ?DiscountTerms $terms, public ?string $note) {}
}
