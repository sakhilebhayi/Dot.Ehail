<?php

namespace App\Domain\Pricing\Http\Controllers\Ops;

use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Support\TimeWindows;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Promotion::query()->orderByDesc('id')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'code' => ['required_if:kind,code', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promotions', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['code', 'automatic'])],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'percent_bp' => ['required_if:discount_type,percent', 'nullable', 'integer', 'min:1', 'max:10000'],
            'amount_minor' => ['required_if:discount_type,fixed', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'max_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(array_keys((array) config('ehail_pricing.currencies')))],
            'eligibility' => ['sometimes', 'nullable', 'array'],
            'eligibility.first_ride_only' => ['sometimes', 'boolean'],
            'eligibility.category_keys' => ['sometimes', 'array'],
            'eligibility.category_keys.*' => ['string', 'exists:ride_categories,key'],
            'eligibility.service_area_ids' => ['sometimes', 'array'],
            'eligibility.service_area_ids.*' => ['integer'],
            'eligibility.min_fare_minor' => ['sometimes', 'integer', 'min:0'],
            'eligibility.timezone' => ['sometimes', 'timezone:all'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'budget_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        if (($errors = TimeWindows::validate($request->input('eligibility.time_windows'))) !== []) {
            throw ValidationException::withMessages(['eligibility.time_windows' => $errors]);
        }

        if ($request->has('eligibility.time_windows')) {
            $valid['eligibility']['time_windows'] = $request->input('eligibility.time_windows');
        }

        if ($valid['kind'] === 'automatic') {
            $valid['code'] = null;
        }

        $promotion = Promotion::query()->create($valid + ['is_active' => true, 'created_by' => $request->user()->id]);
        AuditLog::record('pricing.promotion_created', $request->user(), $promotion, ['code' => $promotion->code, 'kind' => $promotion->kind]);

        return response()->json(['promotion' => $promotion], 201);
    }

    public function deactivate(Request $request, Promotion $promotion): JsonResponse
    {
        $promotion->forceFill(['is_active' => false])->save();
        AuditLog::record('pricing.promotion_deactivated', $request->user(), $promotion, []);

        return response()->json(['promotion' => $promotion]);
    }
}
