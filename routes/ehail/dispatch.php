<?php

// R-46 dispatch routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// JSON API for the wave-2 passenger, driver and ops apps (docs/R46_DISPATCH.md "HTTP API").

use App\Http\Controllers\Ehail\Dispatch\DriverDispatchController;
use App\Http\Controllers\Ehail\Dispatch\OpsRideController;
use App\Http\Controllers\Ehail\Dispatch\PassengerRideController;
use Illuminate\Support\Facades\Route;

Route::prefix('ehail')->name('ehail.dispatch.')->group(function () {
    // Passenger
    Route::post('/rides', [PassengerRideController::class, 'store'])->middleware('throttle:ehail-ride-request')->name('rides.store');
    Route::get('/rides/{ride}/feed', [PassengerRideController::class, 'feed'])->middleware('throttle:ehail-feed')->name('rides.feed');
    Route::post('/rides/{ride}/cancel', [PassengerRideController::class, 'cancel'])->middleware('throttle:ehail-ride-action')->name('rides.cancel');
    Route::patch('/rides/{ride}/schedule', [PassengerRideController::class, 'reschedule'])->middleware('throttle:ehail-ride-action')->name('rides.reschedule');
    Route::post('/rides/{ride}/requote/accept', [PassengerRideController::class, 'acceptRequote'])->middleware('throttle:ehail-ride-action')->name('rides.requote.accept');

    // Driver
    Route::get('/driver/feed', [DriverDispatchController::class, 'feed'])->middleware('throttle:ehail-feed')->name('driver.feed');
    Route::post('/driver/online', [DriverDispatchController::class, 'online'])->middleware('throttle:ehail-ride-action')->name('driver.online');
    Route::post('/driver/offline', [DriverDispatchController::class, 'offline'])->middleware('throttle:ehail-ride-action')->name('driver.offline');
    Route::post('/driver/pause', [DriverDispatchController::class, 'pause'])->middleware('throttle:ehail-ride-action')->name('driver.pause');
    Route::post('/driver/resume', [DriverDispatchController::class, 'resume'])->middleware('throttle:ehail-ride-action')->name('driver.resume');
    Route::post('/driver/heartbeat', [DriverDispatchController::class, 'heartbeat'])->middleware('throttle:ehail-heartbeat')->name('driver.heartbeat');
    Route::post('/offers/{offer}/seen', [DriverDispatchController::class, 'seen'])->middleware('throttle:ehail-ride-action')->name('offers.seen');
    Route::post('/offers/{offer}/accept', [DriverDispatchController::class, 'accept'])->middleware('throttle:ehail-ride-action')->name('offers.accept');
    Route::post('/offers/{offer}/decline', [DriverDispatchController::class, 'decline'])->middleware('throttle:ehail-ride-action')->name('offers.decline');
    Route::post('/driver/rides/{ride}/{stage}', [DriverDispatchController::class, 'stage'])->whereIn('stage', ['arriving', 'arrived', 'start', 'complete'])->middleware('throttle:ehail-ride-action')->name('driver.rides.stage');
    Route::post('/driver/rides/{ride}/no-show', [DriverDispatchController::class, 'noShow'])->middleware('throttle:ehail-ride-action')->name('driver.rides.no-show');
    Route::post('/driver/rides/{ride}/cancel', [DriverDispatchController::class, 'cancel'])->middleware('throttle:ehail-ride-action')->name('driver.rides.cancel');

    // Operations (platform operators)
    Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
        Route::get('/rides', [OpsRideController::class, 'index'])->name('rides.index');
        Route::get('/rides/{ride}/timeline', [OpsRideController::class, 'timeline'])->name('rides.timeline');
        Route::post('/rides/{ride}/force-cancel', [OpsRideController::class, 'forceCancel'])->name('rides.force-cancel');
        Route::post('/rides/{ride}/reassign', [OpsRideController::class, 'reassign'])->name('rides.reassign');
        Route::post('/rides/{ride}/needs-review', [OpsRideController::class, 'needsReview'])->name('rides.needs-review');
        Route::post('/rides/{ride}/resolve', [OpsRideController::class, 'resolve'])->name('rides.resolve');
    });
});
