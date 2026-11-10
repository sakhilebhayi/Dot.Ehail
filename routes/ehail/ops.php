<?php

// R-46 ops routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Http\Controllers\Shell\OpsHomeController;
use Illuminate\Support\Facades\Route;

// ds shell placeholder; the wave-2 operations centre replaces the controller, keeping the name.
Route::get('/ops', OpsHomeController::class)->middleware('operator')->name('ops.home');
