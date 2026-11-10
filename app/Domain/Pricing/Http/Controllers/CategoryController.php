<?php

namespace App\Domain\Pricing\Http\Controllers;

use App\Domain\Catalog\Services\CategoryAvailability;
use App\Domain\Catalog\Services\CategoryOffer;
use App\Domain\Pricing\Data\GeoPoint;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Categories a passenger can choose at a pickup point now (POST: the point is in the body, not the URL).
     */
    public function available(Request $request, CategoryAvailability $availability): JsonResponse
    {
        $valid = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $offers = $availability->availableAt(GeoPoint::from($valid['lat'], $valid['lng']));

        return response()->json([
            'categories' => array_map(fn (CategoryOffer $o) => $o->toArray(), $offers),
            'serviced' => $offers !== [],
        ]);
    }
}
