<?php

namespace App\Domain\Ops\Access;

use App\Domain\Ops\Access\OpsPermission as P;
use Illuminate\Support\Str;

/**
 * The permission each operator-only route needs. The `operator` middleware (EnsurePlatformOperator) consults this
 * map for every route it guards, so the domain APIs that were "operator only" (dispatch, money, pricing, safety,
 * support) get role enforcement without their controllers being rewritten. First matching pattern wins; a route
 * that matches nothing needs the admin role (fail closed), and RoutePermissionsTest fails when any operator route
 * is unmapped, so a new route is never silently admin-only.
 */
final class RoutePermissions
{
    /** @var array<string, list<string>> route-name pattern => any of these permissions */
    public const MAP = [
        // Operations centre pages (this agent)
        'ops.home' => ['*'],
        'ops.live' => [P::LIVE_VIEW],
        'ops.live.feed' => [P::LIVE_VIEW],
        'ops.rides' => [P::RIDES_VIEW],
        'ops.rides.show' => [P::RIDES_VIEW],
        'ops.drivers' => [P::DRIVERS_VIEW],
        'ops.drivers.show' => [P::DRIVERS_VIEW],
        'ops.drivers.suspend' => [P::DRIVERS_SUSPEND],
        'ops.drivers.lift' => [P::DRIVERS_SUSPEND],
        'ops.drivers.appeal' => [P::DRIVERS_SUSPEND],
        'ops.drivers.appeal-decide' => [P::DRIVERS_SUSPEND],
        'ops.drivers.application' => [P::FLEETS_REVIEW],
        'ops.verification' => [P::VERIFICATION_REVIEW],
        'ops.verification.*' => [P::VERIFICATION_REVIEW],
        'ops.safety.access' => [P::AUDIT_VIEW],
        'ops.safety' => [P::SAFETY_VIEW],
        'ops.safety.*' => [P::SAFETY_VIEW],
        'ops.support' => [P::SUPPORT_VIEW],
        'ops.support.*' => [P::SUPPORT_VIEW],
        'ops.pricing' => [P::PRICING_VIEW],
        'ops.pricing.*' => [P::PRICING_VIEW],
        'ops.zones' => [P::PRICING_VIEW, P::ZONES_MANAGE],
        'ops.zones.*' => [P::ZONES_MANAGE],
        'ops.finance' => [P::FINANCE_VIEW],
        'ops.finance.*' => [P::FINANCE_VIEW],
        'ops.insights' => [P::INSIGHTS_VIEW],
        'ops.insights.*' => [P::INSIGHTS_VIEW],
        'ops.audit' => [P::AUDIT_VIEW],
        'ops.audit.*' => [P::AUDIT_VIEW],
        'ops.roles' => [P::ROLES_MANAGE],
        'ops.roles.*' => [P::ROLES_MANAGE],
        'ops.fleets' => [P::FLEETS_REVIEW],
        'ops.fleets.*' => [P::FLEETS_REVIEW],

        // Dispatch operations API (docs/R46_DISPATCH.md section 8)
        'ehail.dispatch.ops.rides.index' => [P::RIDES_VIEW],
        'ehail.dispatch.ops.rides.timeline' => [P::RIDES_VIEW],
        'ehail.dispatch.ops.rides.*' => [P::RIDES_INTERVENE],

        // Money operations API (docs/R46_MONEY.md section 8)
        'money.ops.refund' => [P::FINANCE_REFUND],
        'money.ops.batches.draft' => [P::FINANCE_PAYOUTS],
        'money.ops.batch.export' => [P::FINANCE_PAYOUTS],
        'money.ops.batch.paid' => [P::FINANCE_PAYOUTS],
        'money.ops.batch.transfers' => [P::FINANCE_PAYOUTS],
        'money.ops.payout.*' => [P::FINANCE_PAYOUTS],
        'money.ops.reconciliation.settlement' => [P::FINANCE_MANAGE],
        'money.ops.issues.resolve' => [P::FINANCE_MANAGE],
        'money.ops.incentives.store' => [P::FINANCE_MANAGE],
        'money.ops.incentives.status' => [P::FINANCE_MANAGE],
        'money.ops.incentives.evaluate' => [P::FINANCE_MANAGE],
        'money.ops.ledger.adjustment' => [P::FINANCE_MANAGE],
        'money.ops.*' => [P::FINANCE_VIEW],

        // Pricing admin API (docs/R46_PRICING.md section 11)
        'pricing.ops.rides.fare-adjustments.store' => [P::RIDES_FARE_ADJUST],
        'pricing.ops.rule-sets.index' => [P::PRICING_VIEW],
        'pricing.ops.rule-sets.show' => [P::PRICING_VIEW],
        'pricing.ops.rule-sets.simulate' => [P::PRICING_VIEW],
        'pricing.ops.commission-plans.index' => [P::PRICING_VIEW],
        'pricing.ops.promotions.index' => [P::PRICING_VIEW],
        'pricing.ops.categories.index' => [P::PRICING_VIEW],
        'pricing.ops.*' => [P::PRICING_MANAGE],

        // Verification, safety and trust API (docs/R46_SAFETY.md)
        'safety.ops.verification.*' => [P::VERIFICATION_REVIEW],
        'safety.ops.incidents.queue' => [P::SAFETY_VIEW],
        'safety.ops.incidents.show' => [P::SAFETY_VIEW],
        'safety.ops.reports.index' => [P::SAFETY_VIEW],
        'safety.ops.comments.index' => [P::SAFETY_VIEW],
        'safety.ops.appeals.index' => [P::SAFETY_VIEW],
        'safety.ops.*' => [P::SAFETY_ACT],

        // Support API
        'support.ops.cases.queue' => [P::SUPPORT_VIEW],
        'support.ops.cases.show' => [P::SUPPORT_VIEW],
        'support.ops.cases.*' => [P::SUPPORT_ACT],

        // Pre-R-46 operator desk (its pages redirect into the centre; the actions still work)
        'operator.rides.index' => [P::RIDES_VIEW],
        'operator.rides.complete' => [P::RIDES_INTERVENE],
        'operator.rides.cancel' => [P::RIDES_INTERVENE],
        'operator.rides.waive' => [P::FINANCE_REFUND],
        'operator.payments.review' => [P::FINANCE_REFUND],
        'operator.fleets.*' => [P::FLEETS_REVIEW],
    ];

    /**
     * Permissions (any of) the route needs; ['*'] means any operator.
     *
     * @return list<string>
     */
    public static function for(?string $routeName): array
    {
        if ($routeName === null || $routeName === '') {
            return [P::UNMAPPED];
        }

        foreach (self::MAP as $pattern => $permissions) {
            if ($pattern === $routeName || (str_contains($pattern, '*') && Str::is($pattern, $routeName))) {
                return $permissions === ['*'] ? P::all() : $permissions;
            }
        }

        return [P::UNMAPPED];
    }

    public static function isMapped(string $routeName): bool
    {
        return self::for($routeName) !== [P::UNMAPPED];
    }
}
