<?php

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * The chart of accounts. An account is a type code, optionally with a party id after a colon
 * (driver_payable:12 is what the platform owes driver user 12). Normal balance tells the reports
 * which side is positive. docs/R46_MONEY.md explains each one.
 */
final class Account
{
    public const PASSENGER_RECEIVABLE = 'passenger_receivable';

    public const GATEWAY_CLEARING = 'gateway_clearing';

    public const PLATFORM_BANK = 'platform_bank';

    public const PLATFORM_REVENUE_COMMISSION = 'platform_revenue_commission';

    public const PLATFORM_REVENUE_FEES = 'platform_revenue_fees';

    public const DRIVER_PAYABLE = 'driver_payable';

    public const FLEET_PAYABLE = 'fleet_payable';

    public const CASH_WITH_DRIVER = 'cash_with_driver';

    public const BUSINESS_RECEIVABLE = 'business_receivable';

    public const PROMOTIONS_EXPENSE = 'promotions_expense';

    public const INCENTIVES_EXPENSE = 'incentives_expense';

    public const REFUNDS = 'refunds';

    public const WAIVERS_EXPENSE = 'waivers_expense';

    public const ADJUSTMENTS = 'adjustments';

    public const TAX_PAYABLE = 'tax_payable';

    public const CARD_VERIFICATION_CLEARING = 'card_verification_clearing';

    /** @var array<string, array{label: string, nature: 'asset'|'liability'|'revenue'|'expense', party: ?string}> */
    public const CHART = [
        self::PASSENGER_RECEIVABLE => ['label' => 'Owed by passengers', 'nature' => 'asset', 'party' => null],
        self::GATEWAY_CLEARING => ['label' => 'Held at the payment gateway', 'nature' => 'asset', 'party' => null],
        self::PLATFORM_BANK => ['label' => 'Platform bank account', 'nature' => 'asset', 'party' => null],
        self::CASH_WITH_DRIVER => ['label' => 'Cash fares held by a driver', 'nature' => 'asset', 'party' => 'driver'],
        self::BUSINESS_RECEIVABLE => ['label' => 'Owed by a business account', 'nature' => 'asset', 'party' => 'business'],
        self::CARD_VERIFICATION_CLEARING => ['label' => 'Card verification charges to refund', 'nature' => 'liability', 'party' => null],
        self::DRIVER_PAYABLE => ['label' => 'Owed to a driver', 'nature' => 'liability', 'party' => 'driver'],
        self::FLEET_PAYABLE => ['label' => 'Owed to a fleet owner', 'nature' => 'liability', 'party' => 'fleet'],
        self::TAX_PAYABLE => ['label' => 'Tax collected', 'nature' => 'liability', 'party' => null],
        self::PLATFORM_REVENUE_COMMISSION => ['label' => 'Commission revenue', 'nature' => 'revenue', 'party' => null],
        self::PLATFORM_REVENUE_FEES => ['label' => 'Fee revenue (booking and service fees)', 'nature' => 'revenue', 'party' => null],
        self::PROMOTIONS_EXPENSE => ['label' => 'Promotions funded by the platform', 'nature' => 'expense', 'party' => null],
        self::INCENTIVES_EXPENSE => ['label' => 'Driver incentives', 'nature' => 'expense', 'party' => null],
        self::REFUNDS => ['label' => 'Refunds to passengers', 'nature' => 'expense', 'party' => null],
        self::WAIVERS_EXPENSE => ['label' => 'Fares written off', 'nature' => 'expense', 'party' => null],
        self::ADJUSTMENTS => ['label' => 'Manual adjustments', 'nature' => 'expense', 'party' => null],
    ];

    public static function of(string $type, ?int $partyId = null): string
    {
        if (! isset(self::CHART[$type])) {
            throw new InvalidArgumentException("Unknown ledger account type [{$type}].");
        }

        $needsParty = self::CHART[$type]['party'] !== null;

        if ($needsParty !== ($partyId !== null)) {
            throw new InvalidArgumentException("Account [{$type}] ".($needsParty ? 'needs' : 'takes no').' party id.');
        }

        return $partyId === null ? $type : $type.':'.$partyId;
    }

    public static function driverPayable(int $driverUserId): string
    {
        return self::of(self::DRIVER_PAYABLE, $driverUserId);
    }

    public static function fleetPayable(int $fleetId): string
    {
        return self::of(self::FLEET_PAYABLE, $fleetId);
    }

    public static function cashWithDriver(int $driverUserId): string
    {
        return self::of(self::CASH_WITH_DRIVER, $driverUserId);
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    public static function parse(string $account): array
    {
        [$type, $party] = array_pad(explode(':', $account, 2), 2, null);

        if (! isset(self::CHART[$type])) {
            throw new InvalidArgumentException("Unknown ledger account [{$account}].");
        }

        if ((self::CHART[$type]['party'] !== null) !== ($party !== null) || ($party !== null && ! ctype_digit($party))) {
            throw new InvalidArgumentException("Ledger account [{$account}] has a missing or unexpected party id.");
        }

        return [$type, $party === null ? null : (int) $party];
    }

    /** Debit-normal accounts (assets, expenses) report debit minus credit; the others credit minus debit. */
    public static function isDebitNormal(string $type): bool
    {
        return in_array(self::CHART[$type]['nature'] ?? null, ['asset', 'expense'], true);
    }

    public static function label(string $account): string
    {
        [$type, $party] = self::parse($account);

        return self::CHART[$type]['label'].($party !== null ? ' #'.$party : '');
    }
}
