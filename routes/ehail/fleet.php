<?php

// R-46 fleet routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Http\Controllers\Shell\FleetHomeController;
use Illuminate\Support\Facades\Route;

// ds shell placeholder; wave-2 fleet management replaces the controller, keeping the name.
Route::get('/fleet', FleetHomeController::class)->name('fleet.home');
