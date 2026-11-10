<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Drivers\DriverSuspension;
use App\Domain\Ops\Drivers\SuspensionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /drive/suspensions/{suspension}/appeal: the suspended driver's own appeal (JSON; one per suspension). */
class SuspensionAppealController extends Controller
{
    public function __invoke(Request $request, DriverSuspension $suspension, SuspensionService $suspensions): JsonResponse
    {
        abort_unless($suspension->user_id === $request->user()->id, 404);
        $validated = $request->validate(['text' => ['required', 'string', 'min:10', 'max:2000']]);
        $suspensions->appeal($request->user(), $suspension, $validated['text']);

        return response()->json(['data' => ['id' => $suspension->id, 'appeal_status' => 'open']], 201);
    }
}
