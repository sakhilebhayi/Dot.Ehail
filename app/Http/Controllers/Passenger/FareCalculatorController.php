<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Geo\Support\GeoClientConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public fare calculator (/fares): anyone can set two points on the map and read every line of the fare before
 * signing in. Prices come from pricing's public estimate endpoint (nothing stored, no promotions, never bookable).
 * Address search is offered to signed-in people only, because the geocoder proxy is behind sign-in (Nominatim's
 * one-request-per-second policy is shared by the whole app); guests tap the map or use their location.
 */
class FareCalculatorController extends Controller
{
    public function __invoke(Request $request, GeoClientConfig $geo): View
    {
        return view('passenger.fares', [
            'geoConfig' => $geo->toArray(),
            'categories' => RideCategory::query()->activeRides()->get()->map(fn (RideCategory $c) => $c->toPublicArray())->values(),
            'canSearch' => $request->user() !== null,
        ]);
    }
}
