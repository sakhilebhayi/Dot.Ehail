<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;

/**
 * Platform-operator vetting of fleets. Routes sit behind the `operator`
 * middleware (App\Http\Middleware\EnsurePlatformOperator). A fleet created
 * through a driver application is pending until approved here; until then
 * its owner cannot review driver applications.
 */
class OperatorFleetController extends Controller
{
    /** The fleet review moved into the operations centre (R-46 ops: /ops/fleets); the old address redirects. */
    public function index(): RedirectResponse
    {
        return redirect()->route('ops.fleets');
    }

    public function approve(Fleet $fleet): RedirectResponse
    {
        // Nobody vets their own fleet (S-10); a second operator does.
        abort_if($fleet->owner_user_id === auth()->id(), 403, 'You cannot approve your own fleet.');

        $fleet->update(['status' => Fleet::STATUS_APPROVED]);

        return back()->with('status', "{$fleet->name} approved.");
    }
}
