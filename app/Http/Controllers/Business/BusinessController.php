<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Abilities;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessMember;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Shared by the business portal controllers: the signed-in person's current membership (one person can
 * be on several business accounts; the choice is kept in the session) and the role check.
 */
abstract class BusinessController extends Controller
{
    public const SESSION_KEY = 'business.account_id';

    protected function currentMember(Request $request): ?BusinessMember
    {
        $memberships = BusinessMember::query()->with('account')
            ->where('user_id', $request->user()->id)->where('status', BusinessMember::ACTIVE)
            ->whereHas('account', fn ($q) => $q->whereNot('status', BusinessAccount::CLOSED))
            ->orderBy('id')->get();

        if ($memberships->isEmpty()) {
            return null;
        }

        $chosen = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $memberships->firstWhere('business_account_id', $chosen) ?? $memberships->first();
    }

    /**
     * The membership, or a redirect to the portal home (no account yet), or 403 (role lacks the ability).
     */
    protected function member(Request $request, ?string $ability = null): BusinessMember
    {
        $member = $this->currentMember($request);

        if ($member === null) {
            throw new HttpResponseException($request->expectsJson()
                ? response()->json(['error' => 'no_business_account', 'message' => 'You are not on a business account.'], 404)
                : redirect()->route('business.home'));
        }

        if ($ability !== null && ! Abilities::allows($member, $ability)) {
            abort(403, 'Your role on this business account does not allow this.');
        }

        return $member;
    }

    /**
     * @return array<string, bool>
     */
    protected function can(BusinessMember $member): array
    {
        return collect(array_keys(Abilities::MATRIX))->mapWithKeys(fn ($ability) => [$ability => Abilities::allows($member, $ability)])->all();
    }
}
