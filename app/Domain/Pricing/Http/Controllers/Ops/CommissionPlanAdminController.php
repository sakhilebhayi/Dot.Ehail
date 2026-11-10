<?php

namespace App\Domain\Pricing\Http\Controllers\Ops;

use App\Domain\Pricing\Admin\PricingAdmin;
use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CommissionPlanAdminController extends Controller
{
    public function __construct(private PricingAdmin $admin) {}

    public function index(): JsonResponse
    {
        return response()->json(CommissionPlan::query()->orderBy('lineage_key')->orderByDesc('version')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['commission_plan' => $this->admin->draftCommissionPlan($request->all(), $request->user())], 201);
    }

    public function version(Request $request, CommissionPlan $commissionPlan): JsonResponse
    {
        return response()->json(['commission_plan' => $this->admin->draftCommissionPlan($request->all(), $request->user(), $commissionPlan)], 201);
    }

    public function publish(Request $request, CommissionPlan $commissionPlan): JsonResponse
    {
        $valid = $request->validate(['effective_from' => ['sometimes', 'nullable', 'date']]);

        try {
            return response()->json(['commission_plan' => $this->admin->publishCommissionPlan($commissionPlan, $request->user(), isset($valid['effective_from']) ? Carbon::parse($valid['effective_from']) : null)]);
        } catch (ImmutablePricingRecord $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function retire(Request $request, CommissionPlan $commissionPlan): JsonResponse
    {
        try {
            return response()->json(['commission_plan' => $this->admin->retire($commissionPlan, $request->user())]);
        } catch (ImmutablePricingRecord $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
