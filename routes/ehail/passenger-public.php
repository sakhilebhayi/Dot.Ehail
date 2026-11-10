<?php

// R-46 passenger public routes (public fare calculator), required from routes/ehail/public.php. Routes only.

use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use App\Http\Controllers\Passenger\FareCalculatorController;
use App\Http\Middleware\Passenger\PassengerContentPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/fares', FareCalculatorController::class)->middleware([PassengerContentPolicy::class, AllowGeolocation::class])->name('fares.calculator');
