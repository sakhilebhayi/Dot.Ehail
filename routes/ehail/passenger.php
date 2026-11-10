<?php

// R-46 passenger routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Http\Controllers\Shell\PassengerHomeController;
use App\Http\Controllers\Shell\RoleHomeController;
use Illuminate\Support\Facades\Route;

// Shared entry (PWA start URL): redirects to the home of the person's role. Owned by ds.
Route::get('/home', RoleHomeController::class)->name('home');

// ds shell placeholder; the wave-2 passenger app replaces the controller, keeping the name.
Route::get('/ride', PassengerHomeController::class)->name('ride.home');
