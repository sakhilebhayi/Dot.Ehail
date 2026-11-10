<?php

namespace App\Domain\Pricing\Http\Controllers\Ops;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Support\TimeWindows;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['categories' => RideCategory::query()->with('vehicleRules')->orderBy('display_order')->get()]);
    }

    public function update(Request $request, RideCategory $category): JsonResponse
    {
        $valid = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'capacity' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'luggage' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'driver_rating_floor' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:500'],
            'fleet_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'icon_key' => ['sometimes', 'nullable', 'string', 'max:32'],
            'availability' => ['sometimes', 'nullable', 'array'],
            'availability.service_area_ids' => ['sometimes', 'nullable', 'array'],
            'availability.service_area_ids.*' => ['integer'],
            'policies' => ['sometimes', 'nullable', 'array'],
            'policies.cancellation_free_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'policies.max_wait_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'policies.pin_required' => ['sometimes', 'boolean'],
        ]);

        if ($request->has('availability.windows')) {
            if (($errors = TimeWindows::validate($request->input('availability.windows'))) !== []) {
                throw ValidationException::withMessages(['availability.windows' => $errors]);
            }

            $valid['availability']['windows'] = $request->input('availability.windows');
        }

        if (($valid['is_active'] ?? false) && $category->service_kind !== 'ride') {
            throw ValidationException::withMessages(['is_active' => 'This category belongs to a service that is not built yet and cannot be activated.']);
        }

        $category->fill($valid)->save();
        AuditLog::record('catalog.category_updated', $request->user(), $category, ['changed' => array_keys($valid)]);

        return response()->json(['category' => $category->fresh('vehicleRules')]);
    }
}
