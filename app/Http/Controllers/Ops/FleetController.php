<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use Illuminate\View\View;

/** Fleet review (moved from the pre-R-46 operator page): pending fleets to approve, approved fleets with driver counts. */
class FleetController extends Controller
{
    public function index(): View
    {
        $counts = DriverProfile::withoutGlobalScope('user')->whereNotNull('fleet_id')
            ->selectRaw('fleet_id, status, count(*) as n')->groupBy('fleet_id', 'status')->get()->groupBy('fleet_id');

        return view('ops.fleets.index', [
            'pending' => Fleet::where('status', Fleet::STATUS_PENDING)->with('owner')->oldest()->get(),
            'approved' => Fleet::approved()->with('owner')->orderBy('name')->get(),
            'counts' => $counts,
        ]);
    }
}
