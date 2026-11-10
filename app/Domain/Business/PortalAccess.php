<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Models\User;

/**
 * Which business portal destinations a person sees in the navigation (App\Support\Ui\Navigation asks with
 * "business:<ability>"; "member" means any active membership). Mirrors the checks the controllers make,
 * using the membership chosen in the session (BusinessController::SESSION_KEY).
 */
class PortalAccess
{
    /** @var array<int, ?BusinessMember> */
    private array $resolved = [];

    public function allows(User $user, string $ability): bool
    {
        $member = $this->memberFor($user);

        if ($member === null) {
            return false;
        }

        return $ability === 'member' ? $member->isActive() : Abilities::allows($member, $ability);
    }

    public function memberFor(User $user): ?BusinessMember
    {
        if (array_key_exists($user->id, $this->resolved)) {
            return $this->resolved[$user->id];
        }

        $memberships = BusinessMember::query()->with('account')->where('user_id', $user->id)->where('status', BusinessMember::ACTIVE)
            ->whereHas('account', fn ($q) => $q->whereNot('status', BusinessAccount::CLOSED))->orderBy('id')->get();
        $chosen = app()->bound('session') ? (int) session('business.account_id', 0) : 0;

        return $this->resolved[$user->id] = $memberships->firstWhere('business_account_id', $chosen) ?? $memberships->first();
    }
}
