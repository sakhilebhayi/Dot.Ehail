<?php

// R-46 perf routes, loaded from routes/web.php (inside the auth group). Routes only: the limiter lives in
// EhailPerfServiceProvider (route:cache). docs/R46_PERF.md.

use App\Http\Controllers\Ehail\Dispatch\DriverSyncController;
use Illuminate\Support\Facades\Route;

// One request per driver poll: positions up, dispatch feed down (replaces feed + location + heartbeat polling).
Route::post('/api/ehail/driver/sync', DriverSyncController::class)->middleware('throttle:ehail-driver-sync')->name('ehail.dispatch.driver.sync');
