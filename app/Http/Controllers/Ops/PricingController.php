<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Models\Promotion;
use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pricing and catalogue: tariffs (rule sets by lineage with their version history), the draft editor, the
 * simulator (draft against the live tariff on a route), promotions, ride categories with vehicle rules, and
 * commission plans. Everything is read here; every change posts to the pricing admin API, which validates,
 * versions and audits it.
 */
class PricingController extends Controller
{
    public function index(): View
    {
        $sets = PricingRuleSet::query()->with('category')->orderBy('lineage_key')->orderByDesc('version')->get();
        $lineages = $sets->groupBy('lineage_key')->map(fn ($versions) => [
            'latest' => $versions->first(),
            'live' => $versions->first(fn ($v) => $v->status === 'published' && $v->retired_at === null),
            'draft' => $versions->first(fn ($v) => $v->status === 'draft'),
            'count' => $versions->count(),
        ])->sortBy(fn ($l) => [$l['latest']->category?->display_order ?? 999, $l['latest']->name])->values();

        return view('ops.pricing.index', [
            'lineages' => $lineages,
            'areas' => ServiceArea::query()->pluck('name', 'id'),
            'categories' => RideCategory::query()->orderBy('display_order')->get(),
        ]);
    }

    public function show(Request $request, PricingRuleSet $ruleSet, OpsAccess $access): View
    {
        $versions = PricingRuleSet::query()->where('lineage_key', $ruleSet->lineage_key)->orderByDesc('version')->get();

        return view('ops.pricing.show', [
            'set' => $ruleSet->load('category'),
            'versions' => $versions,
            'areas' => ServiceArea::query()->pluck('name', 'id'),
            'canManage' => $access->can($request->user(), OpsPermission::PRICING_MANAGE),
            'hasDraft' => $versions->contains(fn ($v) => $v->status === 'draft'),
        ]);
    }

    public function simulator(Request $request): View
    {
        return view('ops.pricing.simulator', [
            'categories' => RideCategory::query()->orderBy('display_order')->get(),
            'drafts' => PricingRuleSet::query()->with('category')->where('status', 'draft')->orderBy('name')->get(),
            'preselect' => (int) $request->query('rule_set'),
        ]);
    }

    public function promotions(Request $request, OpsAccess $access): View
    {
        return view('ops.pricing.promotions', [
            'promotions' => Promotion::query()->orderByDesc('is_active')->orderByDesc('id')->get(),
            'categories' => RideCategory::query()->orderBy('display_order')->get(),
            'canManage' => $access->can($request->user(), OpsPermission::PRICING_MANAGE),
        ]);
    }

    public function catalogue(Request $request, OpsAccess $access): View
    {
        return view('ops.pricing.catalogue', [
            'categories' => RideCategory::query()->with('vehicleRules')->orderBy('display_order')->get(),
            'canManage' => $access->can($request->user(), OpsPermission::PRICING_MANAGE),
        ]);
    }

    public function commission(Request $request, OpsAccess $access): View
    {
        $plans = CommissionPlan::query()->orderBy('lineage_key')->orderByDesc('version')->get();

        return view('ops.pricing.commission', [
            'lineages' => $plans->groupBy('lineage_key'),
            'categories' => RideCategory::query()->pluck('name', 'id'),
            'areas' => ServiceArea::query()->pluck('name', 'id'),
            'fleets' => Fleet::query()->pluck('name', 'id'),
            'canManage' => $access->can($request->user(), OpsPermission::PRICING_MANAGE),
        ]);
    }
}
