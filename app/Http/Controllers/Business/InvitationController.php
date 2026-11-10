<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\Models\BusinessMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Accepting an invitation, from the emailed link or from the portal (matched by the signed-in email). */
class InvitationController extends BusinessController
{
    public function show(Request $request, string $token, BusinessAccounts $accounts): View
    {
        $member = $accounts->findInvitation($token);

        return view('business.invitation', [
            'invitation' => $member,
            'token' => $token,
            'emailMatches' => $member !== null && strtolower((string) $request->user()->email) === strtolower($member->email),
            'expired' => $member !== null && ($member->invite_expires_at === null || $member->invite_expires_at->isPast()),
        ]);
    }

    public function accept(Request $request, string $token, BusinessAccounts $accounts): RedirectResponse
    {
        $member = $accounts->findInvitation($token);
        abort_if($member === null, 404, 'This invitation does not exist or was already used.');

        return $this->accepted($request, $accounts->accept($member, $request->user()));
    }

    public function acceptListed(Request $request, int $member, BusinessAccounts $accounts): RedirectResponse
    {
        $invitation = BusinessMember::where('status', BusinessMember::INVITED)->where('email', strtolower((string) $request->user()->email))->findOrFail($member);

        return $this->accepted($request, $accounts->accept($invitation, $request->user()));
    }

    private function accepted(Request $request, BusinessMember $member): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, $member->business_account_id);

        return redirect()->route('business.home')->with('success', 'You joined '.$member->account->displayName().'.');
    }
}
