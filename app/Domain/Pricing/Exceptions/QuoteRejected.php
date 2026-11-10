<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * A booking that cannot use the quote it names. reason: not_found | tampered | expired | already_used |
 * route_mismatch | category_mismatch | promotion_unavailable. Every case means "ask for a new price".
 */
class QuoteRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : match ($reason) {
            'expired' => 'This price has expired. Check the new price before you book.',
            'already_used' => 'This price was already used for another ride.',
            'route_mismatch' => 'The pickup or drop-off changed since this price was given. Check the new price before you book.',
            'category_mismatch' => 'This price is for a different ride category.',
            'promotion_unavailable' => 'The promotion on this price is no longer available. Check the new price before you book.',
            default => 'This price cannot be used. Ask for a new price before you book.',
        });
    }
}
