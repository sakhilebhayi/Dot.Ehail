<?php

namespace App\Domain\Pricing\Http\Controllers\Ops;

use App\Domain\Pricing\Admin\PricingAdmin;
use App\Domain\Pricing\Admin\PricingSimulator;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Operator API for tariffs (ops UI, wave 2). Behind the `operator` middleware; every write is audited by PricingAdmin.
 */
class RuleSetAdminController extends Controller
{
    public function __construct(private PricingAdmin $admin) {}

    public function index(Request $request): JsonResponse
    {
        $valid = $request->validate(['status' => ['sometimes', 'in:draft,published,retired'], 'ride_category_id' => ['sometimes', 'integer']]);

        $sets = PricingRuleSet::query()
            ->when(isset($valid['status']), fn ($q) => $q->where('status', $valid['status']))
            ->when(isset($valid['ride_category_id']), fn ($q) => $q->where('ride_category_id', $valid['ride_category_id']))
            ->orderBy('lineage_key')->orderByDesc('version')
            ->paginate(50);

        return response()->json($sets);
    }

    public function show(PricingRuleSet $ruleSet): JsonResponse
    {
        return response()->json([
            'rule_set' => $ruleSet,
            'versions' => PricingRuleSet::query()->where('lineage_key', $ruleSet->lineage_key)->orderByDesc('version')->get(['id', 'version', 'status', 'effective_from', 'retired_at', 'published_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['rule_set' => $this->admin->draftRuleSet($request->all(), $request->user())], 201);
    }

    public function update(Request $request, PricingRuleSet $ruleSet): JsonResponse
    {
        return $this->guarded(fn () => ['rule_set' => $this->admin->updateDraft($ruleSet, $request->all(), $request->user())]);
    }

    public function version(Request $request, PricingRuleSet $ruleSet): JsonResponse
    {
        return response()->json(['rule_set' => $this->admin->newRuleSetVersion($ruleSet, $request->user(), $request->all())], 201);
    }

    public function publish(Request $request, PricingRuleSet $ruleSet): JsonResponse
    {
        $valid = $request->validate(['effective_from' => ['sometimes', 'nullable', 'date']]);

        return $this->guarded(fn () => ['rule_set' => $this->admin->publishRuleSet($ruleSet, $request->user(), isset($valid['effective_from']) ? Carbon::parse($valid['effective_from']) : null)]);
    }

    public function retire(Request $request, PricingRuleSet $ruleSet): JsonResponse
    {
        $valid = $request->validate(['at' => ['sometimes', 'nullable', 'date']]);

        return $this->guarded(fn () => ['rule_set' => $this->admin->retire($ruleSet, $request->user(), isset($valid['at']) ? Carbon::parse($valid['at']) : null)]);
    }

    public function simulate(Request $request, PricingSimulator $simulator): JsonResponse
    {
        $valid = $request->validate([
            'rule_set_ids' => ['sometimes', 'array', 'max:10'],
            'rule_set_ids.*' => ['integer'],
            'include_live' => ['sometimes', 'boolean'],
            'category' => ['required', 'string', 'exists:ride_categories,key'],
            'pickup_lat' => ['required_with:pickup_lng,dropoff_lat', 'nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required_with:pickup_lat', 'nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['required_with:pickup_lat', 'nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required_with:dropoff_lat', 'nullable', 'numeric', 'between:-180,180'],
            'distance_m' => ['required_without:pickup_lat', 'nullable', 'integer', 'min:0', 'max:2000000'],
            'duration_s' => ['required_without:pickup_lat', 'nullable', 'integer', 'min:0', 'max:172800'],
            'waiting_s' => ['sometimes', 'integer', 'min:0', 'max:7200'],
            'tolls_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'demand_bp' => ['sometimes', 'integer', 'min:10000', 'max:50000'],
            'pickup_fee_codes' => ['sometimes', 'array'],
            'pickup_fee_codes.*' => ['string', 'max:64'],
            'dropoff_fee_codes' => ['sometimes', 'array'],
            'dropoff_fee_codes.*' => ['string', 'max:64'],
            'at' => ['sometimes', 'date'],
        ]);

        $trip = array_intersect_key($valid, array_flip(['distance_m', 'duration_s', 'waiting_s', 'tolls_minor', 'demand_bp', 'pickup_fee_codes', 'dropoff_fee_codes']));

        if (isset($valid['pickup_lat'])) {
            $trip['pickup'] = GeoPoint::from($valid['pickup_lat'], $valid['pickup_lng']);
            $trip['dropoff'] = GeoPoint::from($valid['dropoff_lat'], $valid['dropoff_lng']);
        }

        return response()->json($simulator->simulate($valid['rule_set_ids'] ?? [], $valid['category'], $trip, (bool) ($valid['include_live'] ?? true), isset($valid['at']) ? Carbon::parse($valid['at']) : now()));
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    private function guarded(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (ImmutablePricingRecord $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
