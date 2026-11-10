<?php

// R-46 driver routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Http\Controllers\Shell\DriverHomeController;
use Illuminate\Support\Facades\Route;

// ds shell placeholder; the wave-2 driver app replaces the controller, keeping the name.
Route::get('/drive', DriverHomeController::class)->name('drive.home');
