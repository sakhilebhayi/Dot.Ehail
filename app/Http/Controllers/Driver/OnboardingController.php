<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The onboarding checklist (application, documents, vehicle, review) with the go-online answer from the safety
 * domain's eligibility gate, in the driver's words.
 */
class OnboardingController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $state = DriverState::for($request->user());

        if ($state->profile() === null) {
            return redirect()->route('drive.apply');
        }

        return view('driver.onboarding', [
            'state' => $state,
            'documents' => $state->documents(),
            'eligibility' => $state->eligibility(),
        ]);
    }
}
