<?php

// R-46 driver routes, loaded from routes/web.php inside the auth group. Routes only: no limiters, gates, aliases or
// bindings here (route:cache); limiters live in App\Providers\EhailDriverServiceProvider. The pre-R-46 /drive/apply,
// /drive/vehicles and /drive/documents routes stay registered in routes/web.php and render the driver app's views.

use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use App\Http\Controllers\Driver\AccountController;
use App\Http\Controllers\Driver\DocumentsController;
use App\Http\Controllers\Driver\DriverApiController;
use App\Http\Controllers\Driver\EarningsController;
use App\Http\Controllers\Driver\HomeController;
use App\Http\Controllers\Driver\OnboardingController;
use App\Http\Controllers\Driver\SafetyController;
use App\Http\Controllers\Driver\TripsController;
use Illuminate\Support\Facades\Route;

// Every driver page may read the phone's position (Permissions-Policy geolocation=(self)): the console reports it
// while online, and the other pages use speed to hold typing-heavy forms while the car moves.
Route::middleware(AllowGeolocation::class)->group(function () {
    Route::get('/drive', HomeController::class)->name('drive.home');
    Route::get('/drive/onboarding', OnboardingController::class)->name('drive.onboarding');

    Route::get('/drive/documents', [DocumentsController::class, 'index'])->name('drive.documents');
    Route::post('/drive/documents/verification', [DocumentsController::class, 'store'])->middleware('throttle:ehail-safety-uploads')->name('drive.documents.upload');

    Route::get('/drive/earnings', [EarningsController::class, 'index'])->name('drive.earnings');
    Route::get('/drive/earnings/payouts', [EarningsController::class, 'payouts'])->name('drive.earnings.payouts');
    Route::get('/drive/earnings/payouts/{payout}', [EarningsController::class, 'payout'])->name('drive.earnings.payout');
    Route::post('/drive/earnings/bank-account', [EarningsController::class, 'storeAccount'])->middleware('throttle:ehail-driver-forms')->name('drive.earnings.bank-account');
    Route::post('/drive/earnings/bank-account/{account}/verify', [EarningsController::class, 'verifyAccount'])->middleware('throttle:ehail-driver-forms')->name('drive.earnings.bank-account.verify');

    Route::get('/drive/trips', [TripsController::class, 'index'])->name('drive.trips');
    Route::get('/drive/trips/{ride}', [TripsController::class, 'show'])->name('drive.trips.show');

    Route::get('/drive/safety', [SafetyController::class, 'index'])->name('drive.safety');
    Route::get('/drive/safety/cases/{case}', [SafetyController::class, 'supportCase'])->name('drive.safety.case');

    Route::get('/drive/account', [AccountController::class, 'index'])->name('drive.account');
    Route::post('/drive/account/cash', [AccountController::class, 'cash'])->middleware('throttle:ehail-driver-forms')->name('drive.account.cash');
});

// The driver app's own JSON (everything else it calls belongs to dispatch, geo, money and safety).
Route::prefix('drive/api')->name('drive.api.')->middleware('throttle:ehail-driver-api')->group(function () {
    Route::get('/offers/{offer}/earnings', [DriverApiController::class, 'offerEarnings'])->name('offer-earnings');
    Route::post('/rides/{ride}/arrived', [DriverApiController::class, 'arrived'])->name('arrived');
    Route::get('/rides/{ride}/summary', [DriverApiController::class, 'summary'])->name('summary');
});
