<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Passenger\Models\SavedPlace;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved places (home, work, others). Home and work are one each: saving a new one replaces the old.
 */
class SavedPlaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->listFor($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'kind' => ['required', Rule::in(SavedPlace::KINDS)],
            'label' => ['nullable', 'string', 'max:60'],
            'address' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $user = $request->user();
        $label = trim((string) ($valid['label'] ?? '')) ?: match ($valid['kind']) {
            'home' => 'Home',
            'work' => 'Work',
            default => 'Saved place',
        };

        if ($valid['kind'] === 'other' && SavedPlace::query()->where('user_id', $user->id)->where('kind', 'other')->count() >= SavedPlace::MAX_OTHER) {
            return response()->json(['error' => 'too_many_places', 'message' => 'You can save up to '.SavedPlace::MAX_OTHER.' other places. Remove one first.'], 422);
        }

        $attributes = ['label' => $label, 'address' => $valid['address'], 'lat' => round((float) $valid['lat'], 7), 'lng' => round((float) $valid['lng'], 7)];
        $place = $valid['kind'] === 'other'
            ? SavedPlace::query()->create(['user_id' => $user->id, 'kind' => 'other'] + $attributes)
            : SavedPlace::query()->updateOrCreate(['user_id' => $user->id, 'kind' => $valid['kind']], $attributes);

        return response()->json(['data' => $place->toClientArray(), 'places' => $this->listFor($request)], 201);
    }

    public function destroy(Request $request, SavedPlace $place): JsonResponse
    {
        abort_unless($place->user_id === $request->user()->id, 404);
        $place->delete();

        return response()->json(['removed' => true, 'places' => $this->listFor($request)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listFor(Request $request): array
    {
        return SavedPlace::query()->where('user_id', $request->user()->id)
            ->orderByRaw("case kind when 'home' then 0 when 'work' then 1 else 2 end")->orderBy('label')
            ->get()->map->toClientArray()->values()->all();
    }
}
