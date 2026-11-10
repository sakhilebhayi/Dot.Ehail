<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\FleetRegistration;
use App\Models\Fleet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Platform operators: grant or withdraw a fleet owner's identity-review rights (masked identity numbers on
 * applications, legacy document view). Default off; every change audited.
 */
class FleetOpsController extends FleetController
{
    public function identityReview(Request $request, Fleet $fleet, FleetRegistration $registration): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['granted' => ['required', 'boolean']]);

        return $this->attempt($request, fn () => $registration->setIdentityReview($fleet, (bool) $data['granted'], $request->user()), $data['granted'] ? 'Identity-review rights granted.' : 'Identity-review rights withdrawn.');
    }
}
