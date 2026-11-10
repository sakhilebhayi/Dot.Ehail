<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

class PromotionUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'This promotion is no longer available ('.$reason.').');
    }
}
