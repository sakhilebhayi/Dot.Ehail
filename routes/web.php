<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Ehail\DashboardController;
use App\Http\Controllers\Ehail\DriverApplicationController;
use App\Http\Controllers\Ehail\DriverController;
use App\Http\Controllers\Ehail\DriverDocumentController;
use App\Http\Controllers\Ehail\FleetController;
use App\Http\Controllers\Ehail\OperatorFleetController;
use App\Http\Controllers\Ehail\OperatorRideController;
use App\Http\Controllers\Ehail\PaystackWebhookController;
use App\Http\Controllers\Ehail\RideController;
use App\Http\Controllers\Ehail\RideLifecycleController;
use App\Http\Controllers\Ehail\RidePaymentController;
use App\Http\Controllers\Ehail\RideRatingController;
use App\Http\Controllers\Ehail\RideRequestController;
use App\Http\Controllers\Ehail\VehicleController;
use App\Http\Controllers\RealtimeHealthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Real-time infrastructure health check -- unauthenticated like Laravel's
// own /up, for uptime monitors/load balancers.
Route::get('/up/realtime', [RealtimeHealthController::class, 'check'])->name('health.realtime');

// Paystack calls this server to server: no session, no CSRF token (excluded in bootstrap/app.php),
// authenticated by the signature header alone. Throttled so a flood of forgeries stays cheap.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('paystack.webhook');

Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/rides', [RideController::class, 'index'])->name('rides.index');
    // Literal segments ("create"/"available") must stay registered before
    // "{ride}" below, otherwise they're swallowed by {ride}'s implicit
    // route-model binding.
    Route::get('/rides/create', [RideRequestController::class, 'create'])->name('rides.create');
    Route::post('/rides', [RideRequestController::class, 'store'])->name('rides.store');
    Route::get('/rides/available', [RideLifecycleController::class, 'available'])->name('rides.available');
    Route::get('/rides/{ride}', [RideController::class, 'show'])->name('rides.show');
    Route::post('/rides/{ride}/accept', [RideLifecycleController::class, 'accept'])->name('rides.accept');
    Route::patch('/rides/{ride}/advance', [RideLifecycleController::class, 'advance'])->name('rides.advance');
    Route::post('/rides/{ride}/cancel', [RideLifecycleController::class, 'cancel'])->name('rides.cancel');
    Route::post('/rides/{ride}/pay', [RidePaymentController::class, 'store'])->name('rides.pay');
    Route::get('/rides/{ride}/pay/callback', [RidePaymentController::class, 'callback'])->name('rides.pay.callback');
    Route::post('/rides/{ride}/ratings', [RideRatingController::class, 'store'])->name('rides.ratings.store');
    Route::get('/drivers/{driverProfile}', [DriverController::class, 'show'])->name('drivers.show');
    Route::patch('/drivers/{driverProfile}/toggle-online', [DriverController::class, 'toggleOnline'])->name('drivers.toggle-online');

    Route::post('/drive/documents', [DriverDocumentController::class, 'store'])->name('drivers.documents.store');
    Route::get('/drive/documents/{document}', [DriverDocumentController::class, 'show'])->name('drivers.documents.show');
    Route::delete('/drive/documents/{document}', [DriverDocumentController::class, 'destroy'])->name('drivers.documents.destroy');

    Route::get('/drive/vehicles', [VehicleController::class, 'index'])->name('drivers.vehicles.index');
    Route::post('/drive/vehicles', [VehicleController::class, 'store'])->name('drivers.vehicles.store');
    Route::get('/drive/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('drivers.vehicles.edit');
    Route::patch('/drive/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('drivers.vehicles.update');
    Route::post('/drive/vehicles/{vehicle}/activate', [VehicleController::class, 'activate'])->name('drivers.vehicles.activate');
    Route::post('/drive/vehicles/{vehicle}/deactivate', [VehicleController::class, 'deactivate'])->name('drivers.vehicles.deactivate');

    Route::get('/drive/apply', [DriverApplicationController::class, 'create'])->name('drive.apply');
    Route::post('/drive/apply', [DriverApplicationController::class, 'store'])->name('drive.apply.store');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/fleets', [OperatorFleetController::class, 'index'])->name('fleets.index');
        Route::post('/fleets/{fleet}/approve', [OperatorFleetController::class, 'approve'])->name('fleets.approve');
        Route::get('/rides', [OperatorRideController::class, 'index'])->name('rides.index');
        Route::post('/rides/{ride}/complete', [OperatorRideController::class, 'complete'])->name('rides.complete');
        Route::post('/rides/{ride}/cancel', [OperatorRideController::class, 'cancel'])->name('rides.cancel');
        Route::post('/rides/{ride}/waive', [OperatorRideController::class, 'waive'])->name('rides.waive');
        Route::post('/payments/{payment}/review', [OperatorRideController::class, 'reviewPayment'])->name('payments.review');
    });

    Route::get('/fleets/{fleet}', [FleetController::class, 'show'])->name('fleets.show');
    Route::post('/fleets/{fleet}/drivers/{driverProfileId}/approve', [FleetController::class, 'approveDriver'])->name('fleets.drivers.approve');
    Route::post('/fleets/{fleet}/drivers/{driverProfileId}/reject', [FleetController::class, 'rejectDriver'])->name('fleets.drivers.reject');
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
