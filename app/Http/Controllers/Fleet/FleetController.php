<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Base of the fleet portal controllers: resolves the fleet a request is about and checks the area.
 */
abstract class FleetController extends Controller
{
    public function __construct(protected readonly FleetAccess $access) {}

    /**
     * The current fleet, if this person may use $area of it. 404 when they work on no fleet (or asked for one they
     * cannot see), 403 when the area is not theirs.
     */
    protected function fleet(Request $request, string $area, bool $write = false): Fleet
    {
        $fleet = $this->access->current($request);
        abort_if($fleet === null, 404);
        abort_unless($this->access->can($request->user(), $fleet, $area, $write), 403);

        return $fleet;
    }

    /**
     * Run a fleet action; a refusal goes back to the form with its sentence (and its status for JSON clients).
     */
    protected function attempt(Request $request, Closure $action, string $success): RedirectResponse|JsonResponse
    {
        try {
            $result = $action();
        } catch (FleetActionRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $refused->reason, 'message' => $refused->getMessage()], $refused->status);
            }

            abort_if($refused->status === 403 || $refused->status === 404, $refused->status, $refused->getMessage());

            return back()->withErrors(['fleet' => $refused->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $success, 'result' => is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : $result]);
        }

        return $result instanceof RedirectResponse ? $result->with('status', $success) : back()->with('status', $success);
    }
}
