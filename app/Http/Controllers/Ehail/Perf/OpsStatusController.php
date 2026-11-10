<?php

namespace App\Http\Controllers\Ehail\Perf;

use App\Domain\Health\HealthReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /ops/status (operators): the data source for an operations status panel (docs/R46_PERF.md section 6).
 * The full health report (counts, ages and states; no personal data), computed at most every 15 s.
 */
class OpsStatusController extends Controller
{
    public function __invoke(HealthReport $report): JsonResponse
    {
        $result = cache()->remember('ehail:health:ops-report', 15, fn () => $report->run());

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
