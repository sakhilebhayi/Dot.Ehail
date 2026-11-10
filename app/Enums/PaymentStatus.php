<?php

namespace App\Enums;

/**
 * Where a ride's fare stands. Stored in rides.payment_status.
 *
 * not_due  nothing to collect: a ride that has not completed, a zero fare, a ride that completed
 *          before online payment existed or while it was switched off
 * unpaid   completed with a fare, nobody has started paying
 * pending  a Paystack payment was started and is not confirmed yet
 * paid     a verified Paystack charge for exactly the fare was recorded
 * failed   the last attempt was declined; the passenger can try again
 * waived   an operator wrote the fare off, with a reason
 * awaiting_cash  the passenger chose cash; the driver has not confirmed receiving it yet
 */
enum PaymentStatus: string
{
    case NotDue = 'not_due';
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Waived = 'waived';
    case AwaitingCash = 'awaiting_cash';

    public function label(): string
    {
        return match ($this) {
            self::NotDue => 'No payment due',
            self::Unpaid => 'Unpaid',
            self::Pending => 'Payment started',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Waived => 'Waived',
            self::AwaitingCash => 'Cash due to the driver',
        };
    }

    /**
     * States in which the passenger can still pay, and an operator can still waive.
     *
     * @return array<int, self>
     */
    public static function collectable(): array
    {
        return [self::Unpaid, self::Pending, self::Failed];
    }

    /**
     * @return array<int, string>
     */
    public static function collectableValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::collectable());
    }
}
