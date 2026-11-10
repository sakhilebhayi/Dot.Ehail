<?php

// R-46 geo routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// Limiters ehail-geo-* are defined in App\Domain\Geo\EhailGeoServiceProvider::boot().

use App\Domain\Geo\Http\Controllers\DriverLocationController;
use App\Domain\Geo\Http\Controllers\GeoDemoController;
use App\Domain\Geo\Http\Controllers\GeoLookupController;
use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use Illuminate\Support\Facades\Route;

Route::prefix('geo')->name('geo.')->group(function () {
    Route::get('/config', [GeoLookupController::class, 'config'])->middleware('throttle:ehail-geo-read')->name('config');
    Route::get('/search', [GeoLookupController::class, 'search'])->middleware('throttle:ehail-geo-search')->name('search');
    Route::get('/reverse', [GeoLookupController::class, 'reverse'])->middleware('throttle:ehail-geo-search')->name('reverse');
    Route::get('/route', [GeoLookupController::class, 'route'])->middleware('throttle:ehail-geo-route')->name('route');
    Route::get('/zones', [GeoLookupController::class, 'zones'])->middleware('throttle:ehail-geo-read')->name('zones');

    Route::post('/driver/location', [DriverLocationController::class, 'store'])->middleware('throttle:ehail-geo-location')->name('driver.location');
    Route::get('/rides/{ride}/driver-location', [DriverLocationController::class, 'forRide'])->middleware('throttle:ehail-geo-read')->name('rides.driver-location');

    // 404 unless APP_ENV=local (checked in the controller, so a cached route table cannot expose it).
    Route::get('/demo', GeoDemoController::class)->middleware(AllowGeolocation::class)->name('demo');
});
