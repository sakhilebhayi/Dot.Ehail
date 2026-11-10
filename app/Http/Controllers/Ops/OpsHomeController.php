<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** `/ops`: sends each operator to the first screen their role opens (a finance operator lands on Finance, etc.). */
class OpsHomeController extends Controller
{
    public const ORDER = ['ops.live', 'ops.rides', 'ops.verification', 'ops.safety', 'ops.support', 'ops.finance', 'ops.pricing', 'ops.insights', 'ops.drivers', 'ops.fleets', 'ops.audit', 'ops.roles'];

    public function __invoke(Request $request, OpsAccess $access): RedirectResponse
    {
        foreach (self::ORDER as $route) {
            if ($access->allowsRoute($request->user(), $route)) {
                return redirect()->route($route);
            }
        }

        abort(403);
    }
}
