<?php

namespace App\Domain\Pricing\Http\Controllers\Concerns;

use App\Domain\Pricing\Data\GeoPoint;
use Illuminate\Http\Request;

/**
 * Pickup and drop-off come in the request BODY (never the query string: coordinates are personal data).
 * Any amount a client sends (total, fare, price) is not part of these rules and is never read.
 */
trait ReadsTripPoints
{
    /**
     * @return array{0: GeoPoint, 1: GeoPoint, 2: array<string, mixed>}
     */
    protected function tripPoints(Request $request, array $extra = []): array
    {
        $valid = $request->validate(array_merge([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'category' => ['sometimes', 'nullable', 'string', 'max:32'],
        ], $extra));

        return [GeoPoint::from($valid['pickup_lat'], $valid['pickup_lng']), GeoPoint::from($valid['dropoff_lat'], $valid['dropoff_lng']), $valid];
    }
}
