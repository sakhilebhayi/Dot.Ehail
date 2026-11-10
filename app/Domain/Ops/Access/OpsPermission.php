<?php

namespace App\Domain\Ops\Access;

/**
 * Operations permissions. A role is a set of these (OpsRole::permissions()); routes and policies ask for one.
 * Least privilege is expressed here: no role except admin holds both identity documents and money.
 */
final class OpsPermission
{
    public const LIVE_VIEW = 'live.view';

    public const RIDES_VIEW = 'rides.view';

    public const RIDES_INTERVENE = 'rides.intervene';

    public const RIDES_FARE_ADJUST = 'rides.fare_adjust';

    public const DRIVERS_VIEW = 'drivers.view';

    public const DRIVERS_POSITIONS = 'drivers.positions';

    public const DRIVERS_SUSPEND = 'drivers.suspend';

    public const DRIVERS_EARNINGS = 'drivers.earnings';

    public const VERIFICATION_REVIEW = 'verification.review';

    public const FLEETS_REVIEW = 'fleets.review';

    public const SAFETY_VIEW = 'safety.view';

    public const SAFETY_ACT = 'safety.act';

    public const SUPPORT_VIEW = 'support.view';

    public const SUPPORT_ACT = 'support.act';

    public const PRICING_VIEW = 'pricing.view';

    public const PRICING_MANAGE = 'pricing.manage';

    public const ZONES_MANAGE = 'zones.manage';

    public const FINANCE_VIEW = 'finance.view';

    public const FINANCE_REFUND = 'finance.refund';

    public const FINANCE_PAYOUTS = 'finance.payouts';

    public const FINANCE_MANAGE = 'finance.manage';

    public const BUSINESS_VIEW = 'business.view';

    public const BUSINESS_MANAGE = 'business.manage';

    public const INSIGHTS_VIEW = 'insights.view';

    public const AUDIT_VIEW = 'audit.view';

    public const ROLES_MANAGE = 'roles.manage';

    /** Fallback for an operator route nobody has mapped: admin only (fail closed). */
    public const UNMAPPED = 'admin.only';

    /** @var array<string, string> permission => what it allows, in the words shown on the roles page */
    public const DESCRIPTIONS = [
        self::LIVE_VIEW => 'See the live map and queues',
        self::RIDES_VIEW => 'Open rides, their timelines, fares and payments',
        self::RIDES_INTERVENE => 'Reassign, cancel, flag and resolve rides',
        self::RIDES_FARE_ADJUST => 'Adjust a finalised fare with a reason',
        self::DRIVERS_VIEW => 'Open drivers, their trips and factual stats',
        self::DRIVERS_POSITIONS => 'See where online drivers are',
        self::DRIVERS_SUSPEND => 'Suspend and reinstate drivers, decide appeals',
        self::DRIVERS_EARNINGS => 'See drivers\' earnings and payout status',
        self::VERIFICATION_REVIEW => 'Open identity and vehicle documents, approve or reject them',
        self::FLEETS_REVIEW => 'Approve fleets and driver applications',
        self::SAFETY_VIEW => 'See incidents, check-ins, reports and flagged patterns',
        self::SAFETY_ACT => 'Acknowledge, escalate and resolve incidents; review reports',
        self::SUPPORT_VIEW => 'See support cases and lost property',
        self::SUPPORT_ACT => 'Reply to, assign and resolve support cases',
        self::PRICING_VIEW => 'See tariffs, promotions, categories and commission plans',
        self::PRICING_MANAGE => 'Draft and publish tariffs, promotions, categories and commission plans',
        self::ZONES_MANAGE => 'Draw and switch service areas and zones',
        self::FINANCE_VIEW => 'See payments, payouts, reconciliation and the ledger',
        self::FINANCE_REFUND => 'Refund payments and write off unpaid fares',
        self::FINANCE_PAYOUTS => 'Draft, export and settle payout batches',
        self::FINANCE_MANAGE => 'Post ledger adjustments, resolve finance issues, run incentives',
        self::BUSINESS_VIEW => 'See business accounts and their review state',
        self::BUSINESS_MANAGE => 'Approve, decline, suspend business accounts; set credit and terms; record their payments and statements',
        self::INSIGHTS_VIEW => 'See operational insights and export them',
        self::AUDIT_VIEW => 'Read the audit log',
        self::ROLES_MANAGE => 'Grant and remove operator roles',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }
}
