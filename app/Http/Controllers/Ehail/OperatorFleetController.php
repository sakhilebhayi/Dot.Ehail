<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Platform-operator vetting of fleets. Routes sit behind the `operator`
 * middleware (App\Http\Middleware\EnsurePlatformOperator). A fleet created
 * through a driver application is pending until approved here; until then
 * its owner cannot review driver applications.
 */
class OperatorFleetController extends Controller
{
    public function index(): View
    {
        return view('operator.fleets', [
            'pending' => Fleet::where('status', Fleet::STATUS_PENDING)->with('owner')->oldest()->get(),
            'approved' => Fleet::approved()->with('owner')->orderBy('name')->get(),
        ]);
    }

    public function approve(Fleet $fleet): RedirectResponse
    {
        $fleet->update(['status' => Fleet::STATUS_APPROVED]);

        return back()->with('status', "{$fleet->name} approved.");
    }
}
