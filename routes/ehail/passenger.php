<?php

// R-46 passenger routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// Passenger app (docs/R46_UI_PASSENGER.md). Limiters live in EhailPassengerServiceProvider.

use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use App\Http\Controllers\Passenger\AccountController;
use App\Http\Controllers\Passenger\BookingController;
use App\Http\Controllers\Passenger\PassengerApiController;
use App\Http\Controllers\Passenger\SafetyCentreController;
use App\Http\Controllers\Passenger\SavedPlaceController;
use App\Http\Controllers\Passenger\SupportController;
use App\Http\Controllers\Passenger\TripController;
use App\Http\Controllers\Shell\RoleHomeController;
use App\Http\Middleware\Passenger\PassengerContentPolicy;
use Illuminate\Support\Facades\Route;

// Shared entry (PWA start URL): redirects to the home of the person's role. Owned by ds.
Route::get('/home', RoleHomeController::class)->name('home');

Route::prefix('ride')->name('ride.')->middleware(PassengerContentPolicy::class)->group(function () {
    // Pages that can ask for the device position ("use my location", SOS location) allow geolocation for this origin only.
    Route::get('/', [BookingController::class, 'show'])->middleware(AllowGeolocation::class)->name('home');
    Route::get('/trips', [TripController::class, 'index'])->name('trips');
    Route::get('/trips/{ride}', [TripController::class, 'show'])->middleware(AllowGeolocation::class)->name('trip');
    Route::get('/safety', [SafetyCentreController::class, 'index'])->middleware(AllowGeolocation::class)->name('safety');
    Route::get('/safety/incidents/{incident}', [SafetyCentreController::class, 'incident'])->name('safety.incident');
    Route::get('/help', [SupportController::class, 'index'])->name('support');
    Route::get('/help/new', [SupportController::class, 'create'])->name('support.create');
    Route::get('/help/{case}', [SupportController::class, 'show'])->name('support.show');
    Route::get('/account', [AccountController::class, 'index'])->name('account');

    Route::prefix('api')->name('api.')->middleware('throttle:ehail-passenger')->group(function () {
        Route::get('/places', [SavedPlaceController::class, 'index'])->name('places.index');
        Route::post('/places', [SavedPlaceController::class, 'store'])->name('places.store');
        Route::delete('/places/{place}', [SavedPlaceController::class, 'destroy'])->name('places.destroy');
        Route::get('/rides/{ride}/overview', [PassengerApiController::class, 'overview'])->name('rides.overview');
        Route::post('/pickup-etas', [PassengerApiController::class, 'pickupEtas'])->middleware('throttle:ehail-passenger-eta')->name('pickup-etas');
    });
});
