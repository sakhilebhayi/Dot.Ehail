<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Geo\Geometry\Polygon;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Service areas and zones: drawn and edited on the map, switched on and off. Writes go through the geo models
 * (their HasPolygon trait normalises the polygon and keeps the bounding box), validated here, audited with the
 * before and after shape. Editing an approximate reference area clears its "approximate" flag only when asked.
 */
class ZoneController extends Controller
{
    public function index(Request $request, OpsAccess $access): View
    {
        $areas = ServiceArea::query()->orderBy('name')->get();
        $zones = Zone::query()->orderBy('name')->get();

        return view('ops.zones.index', [
            'areas' => $areas,
            'zones' => $zones,
            'canEdit' => $access->can($request->user(), OpsPermission::ZONES_MANAGE),
            'shapes' => [
                'areas' => $areas->map(fn (ServiceArea $a) => ['id' => $a->id, 'name' => $a->name, 'polygon' => $a->polygon, 'active' => $a->is_active, 'url' => route('ops.zones.areas.update', $a->id), 'zones_url' => route('ops.zones.store', $a->id)])->values(),
                'zones' => $zones->map(fn (Zone $z) => ['id' => $z->id, 'area_id' => $z->service_area_id, 'name' => $z->name, 'kind' => $z->kind, 'polygon' => $z->polygon, 'active' => $z->is_active, 'url' => route('ops.zones.update', $z->id)])->values(),
            ],
        ]);
    }

    public function storeArea(Request $request, OpsAudit $audit): JsonResponse
    {
        $data = $this->validateArea($request, true);
        $area = ServiceArea::create($data + ['slug' => $this->slug(ServiceArea::class, $data['name']), 'is_approximate' => false, 'source_note' => 'Drawn by operations']);
        $audit->action('ops.service_area_created', $request->user(), $area, ['name' => $area->name, 'vertices' => count($area->polygon)]);

        return response()->json(['data' => ['id' => $area->id]], 201);
    }

    public function updateArea(Request $request, ServiceArea $area, OpsAudit $audit): JsonResponse
    {
        $data = $this->validateArea($request, false);
        $before = $area->only(['name', 'is_active', 'polygon']);
        if (isset($data['polygon'])) {
            $data['is_approximate'] = (bool) $request->boolean('confirm_exact') ? false : $area->is_approximate;
        }
        $area->fill($data)->save();
        $audit->action('ops.service_area_updated', $request->user(), $area, ['before' => $before, 'after' => $area->only(['name', 'is_active', 'polygon'])]);

        return response()->json(['data' => ['id' => $area->id, 'is_active' => $area->is_active]]);
    }

    public function storeZone(Request $request, ServiceArea $area, OpsAudit $audit): JsonResponse
    {
        $data = $this->validateZone($request, true);
        $zone = Zone::create($data + ['service_area_id' => $area->id, 'slug' => $this->slug(Zone::class, $data['name']), 'is_approximate' => false, 'source_note' => 'Drawn by operations']);
        $audit->action('ops.zone_created', $request->user(), $zone, ['name' => $zone->name, 'kind' => $zone->kind, 'area_id' => $area->id]);

        return response()->json(['data' => ['id' => $zone->id]], 201);
    }

    public function updateZone(Request $request, Zone $zone, OpsAudit $audit): JsonResponse
    {
        $data = $this->validateZone($request, false);
        $before = $zone->only(['name', 'kind', 'fee_code', 'is_active', 'polygon']);
        $zone->fill($data)->save();
        $audit->action('ops.zone_updated', $request->user(), $zone, ['before' => $before, 'after' => $zone->only(['name', 'kind', 'fee_code', 'is_active', 'polygon'])]);

        return response()->json(['data' => ['id' => $zone->id, 'is_active' => $zone->is_active]]);
    }

    /** @return array<string, mixed> */
    private function validateArea(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'polygon' => [$req, 'array', 'min:3', 'max:500'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.0' => ['numeric', 'between:-90,90'],
            'polygon.*.1' => ['numeric', 'between:-180,180'],
        ]);
        $this->checkShape($data);

        return $data + ($creating ? ['country_code' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'is_active' => (bool) ($data['is_active'] ?? false)] : []);
    }

    /** @return array<string, mixed> */
    private function validateZone(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'kind' => [$req, Rule::in(Zone::KINDS)],
            'fee_code' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'is_active' => ['sometimes', 'boolean'],
            'polygon' => [$req, 'array', 'min:3', 'max:500'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.0' => ['numeric', 'between:-90,90'],
            'polygon.*.1' => ['numeric', 'between:-180,180'],
        ]);
        $this->checkShape($data);

        return $data + ($creating ? ['is_active' => (bool) ($data['is_active'] ?? false)] : []);
    }

    /** @param array<string, mixed> $data */
    private function checkShape(array $data): void
    {
        if (! isset($data['polygon'])) {
            return;
        }

        try {
            Polygon::fromPairs(array_map(fn ($pair) => [(float) $pair[0], (float) $pair[1]], $data['polygon']));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['polygon' => $e->getMessage()]);
        }
    }

    private function slug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'area';
        $slug = $base;
        for ($i = 2; $model::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
