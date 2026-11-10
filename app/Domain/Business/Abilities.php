<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessMember;

/**
 * What each business role may do. Checked on every business route (BusinessController::authorizeBusiness)
 * and again inside the services that change data.
 *
 *  account_admin   everything
 *  travel_manager  people (travellers only), policies, approvals, all trips, booking for others
 *  finance_viewer  read only: overview, people, policies, trips, statements
 *  traveller       their own trips, allowance and approval requests
 */
final class Abilities
{
    public const MATRIX = [
        'view_overview' => [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::FINANCE],
        'view_people' => [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::FINANCE],
        'manage_people' => [BusinessMember::ADMIN, BusinessMember::MANAGER],
        'manage_departments' => [BusinessMember::ADMIN],
        'view_policies' => [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::FINANCE],
        'manage_policies' => [BusinessMember::ADMIN, BusinessMember::MANAGER],
        'decide_approvals' => [BusinessMember::ADMIN, BusinessMember::MANAGER],
        'view_all_trips' => [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::FINANCE],
        'view_statements' => [BusinessMember::ADMIN, BusinessMember::FINANCE],
        'manage_billing' => [BusinessMember::ADMIN],
        'book_for_others' => [BusinessMember::ADMIN, BusinessMember::MANAGER],
        'ride' => [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::TRAVELLER],
    ];

    public static function allows(?BusinessMember $member, string $ability): bool
    {
        return $member !== null && $member->isActive() && in_array($member->role, self::MATRIX[$ability] ?? [], true);
    }

    /** Roles a member with this role may give to someone else. */
    public static function assignableRoles(BusinessMember $member): array
    {
        return match ($member->role) {
            BusinessMember::ADMIN => array_keys(BusinessMember::ROLES),
            BusinessMember::MANAGER => [BusinessMember::TRAVELLER],
            default => [],
        };
    }
}
