<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/fleet` shell placeholder (ds agent, R-46 wave 1). Lists the fleets this person owns (real rows, linking to the
 * fleet page that works today) or says how to register one. Wave-2 fleet management replaces this controller.
 */
class FleetHomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $fleets = Fleet::where('owner_user_id', $request->user()->getKey())
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return view('shell.fleet-home', ['fleets' => $fleets]);
    }
}
