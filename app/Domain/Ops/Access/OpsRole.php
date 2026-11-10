<?php

namespace App\Domain\Ops\Access;

use App\Domain\Ops\Access\OpsPermission as P;

/**
 * Operator roles (brief section 18). Each is a fixed set of permissions; a person may hold several.
 * Least privilege: finance never opens identity documents; reviewers never see earnings or payouts.
 */
enum OpsRole: string
{
    case OpsAgent = 'ops_agent';
    case Dispatcher = 'dispatcher';
    case VerificationReviewer = 'verification_reviewer';
    case SafetyOfficer = 'safety_officer';
    case SupportAgent = 'support_agent';
    case Finance = 'finance';
    case PricingManager = 'pricing_manager';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::OpsAgent => 'Operations agent',
            self::Dispatcher => 'Dispatcher',
            self::VerificationReviewer => 'Verification reviewer',
            self::SafetyOfficer => 'Safety officer',
            self::SupportAgent => 'Support agent',
            self::Finance => 'Finance',
            self::PricingManager => 'Pricing manager',
            self::Admin => 'Administrator',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::OpsAgent => 'Watches the live board, rides, safety and support without acting on them.',
            self::Dispatcher => 'Works the ride queues: reassigns, cancels, flags and resolves rides.',
            self::VerificationReviewer => 'Reviews driver and vehicle documents, applications and fleets. No money.',
            self::SafetyOfficer => 'Owns incidents and reports, suspends drivers, reads the audit trail.',
            self::SupportAgent => 'Answers support cases and lost property; adjusts fares on disputes.',
            self::Finance => 'Payments, refunds, payouts, reconciliation, the ledger and business accounts. No identity documents.',
            self::PricingManager => 'Tariffs, promotions, categories, commission plans and service areas.',
            self::Admin => 'Everything, including granting roles.',
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::OpsAgent => [P::LIVE_VIEW, P::RIDES_VIEW, P::DRIVERS_VIEW, P::SAFETY_VIEW, P::SUPPORT_VIEW, P::BUSINESS_VIEW, P::INSIGHTS_VIEW],
            self::Dispatcher => [P::LIVE_VIEW, P::RIDES_VIEW, P::RIDES_INTERVENE, P::DRIVERS_VIEW, P::DRIVERS_POSITIONS, P::INSIGHTS_VIEW],
            self::VerificationReviewer => [P::DRIVERS_VIEW, P::VERIFICATION_REVIEW, P::FLEETS_REVIEW],
            self::SafetyOfficer => [P::LIVE_VIEW, P::RIDES_VIEW, P::DRIVERS_VIEW, P::DRIVERS_POSITIONS, P::DRIVERS_SUSPEND, P::SAFETY_VIEW, P::SAFETY_ACT, P::SUPPORT_VIEW, P::AUDIT_VIEW],
            self::SupportAgent => [P::RIDES_VIEW, P::RIDES_FARE_ADJUST, P::DRIVERS_VIEW, P::SAFETY_VIEW, P::SUPPORT_VIEW, P::SUPPORT_ACT],
            self::Finance => [P::RIDES_VIEW, P::RIDES_FARE_ADJUST, P::DRIVERS_VIEW, P::DRIVERS_EARNINGS, P::FINANCE_VIEW, P::FINANCE_REFUND, P::FINANCE_PAYOUTS, P::FINANCE_MANAGE, P::BUSINESS_VIEW, P::BUSINESS_MANAGE, P::INSIGHTS_VIEW],
            self::PricingManager => [P::PRICING_VIEW, P::PRICING_MANAGE, P::ZONES_MANAGE, P::INSIGHTS_VIEW],
            self::Admin => P::all(),
        };
    }
}
