<?php

// R-46 public pricing routes (fare calculator), required from routes/ehail/public.php. Routes only: limiters live in
// EhailPricingServiceProvider (route:cache).

use App\Domain\Pricing\Http\Controllers\FareEstimateController;
use Illuminate\Support\Facades\Route;

Route::get('/fare-estimate/categories', [FareEstimateController::class, 'categories'])->middleware('throttle:ehail-fare-estimate')->name('pricing.estimate.categories');
Route::post('/fare-estimate', [FareEstimateController::class, 'store'])->middleware('throttle:ehail-fare-estimate')->name('pricing.estimate');
