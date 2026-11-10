<?php

// R-46 business routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Http\Controllers\Shell\BusinessHomeController;
use Illuminate\Support\Facades\Route;

// ds shell placeholder; wave-2 business accounts replace the controller, keeping the name.
Route::get('/business', BusinessHomeController::class)->name('business.home');
