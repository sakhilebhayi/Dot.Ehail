<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/drive` shell placeholder (ds agent, R-46 wave 1). Shows the driver's real profile state (approved, online) when
 * there is one, and the way in when there is not. The wave-2 driver app replaces this controller.
 */
class DriverHomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->getKey())
            ->first(['id', 'status', 'is_online', 'fleet_id']);

        return view('shell.driver-home', ['profile' => $profile]);
    }
}
