<?php

// R-46 fleet routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// The fleet a page is about comes from ?fleet=<id> (remembered in the session), so navigation routes take no
// parameters (App\Support\Ui\Navigation). The legacy /fleets/{fleet} pages stay in routes/web.php.

use App\Http\Controllers\Fleet\FleetDocumentController;
use App\Http\Controllers\Fleet\FleetDriverController;
use App\Http\Controllers\Fleet\FleetEarningsController;
use App\Http\Controllers\Fleet\FleetMaintenanceController;
use App\Http\Controllers\Fleet\FleetOpsController;
use App\Http\Controllers\Fleet\FleetOverviewController;
use App\Http\Controllers\Fleet\FleetRegistrationController;
use App\Http\Controllers\Fleet\FleetSettingsController;
use App\Http\Controllers\Fleet\FleetTripController;
use App\Http\Controllers\Fleet\FleetVehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('fleet')->name('fleet.')->group(function () {
    Route::get('/', FleetOverviewController::class)->name('home');
    Route::get('/alerts', [FleetOverviewController::class, 'alerts'])->name('alerts');

    Route::get('/register', [FleetRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [FleetRegistrationController::class, 'store'])->middleware('throttle:fleet-writes')->name('register.store');

    Route::get('/drivers', [FleetDriverController::class, 'index'])->name('drivers');
    Route::get('/drivers/{profile}', [FleetDriverController::class, 'show'])->whereNumber('profile')->name('drivers.show');
    Route::post('/applications/{profile}/approve', [FleetDriverController::class, 'approve'])->whereNumber('profile')->middleware('throttle:fleet-writes')->name('applications.approve');
    Route::post('/applications/{profile}/reject', [FleetDriverController::class, 'reject'])->whereNumber('profile')->middleware('throttle:fleet-writes')->name('applications.reject');

    Route::get('/vehicles', [FleetVehicleController::class, 'index'])->name('vehicles');
    Route::get('/vehicles/new', [FleetVehicleController::class, 'create'])->name('vehicles.create');
    Route::post('/vehicles', [FleetVehicleController::class, 'store'])->middleware('throttle:fleet-writes')->name('vehicles.store');
    Route::get('/vehicles/{vehicle}', [FleetVehicleController::class, 'show'])->whereNumber('vehicle')->name('vehicles.show');
    Route::middleware('throttle:fleet-writes')->whereNumber('vehicle')->group(function () {
        Route::patch('/vehicles/{vehicle}', [FleetVehicleController::class, 'update'])->name('vehicles.update');
        Route::post('/vehicles/{vehicle}/retire', [FleetVehicleController::class, 'retire'])->name('vehicles.retire');
        Route::post('/vehicles/{vehicle}/reinstate', [FleetVehicleController::class, 'reinstate'])->name('vehicles.reinstate');
        Route::post('/vehicles/{vehicle}/permits', [FleetVehicleController::class, 'grantPermit'])->name('vehicles.permits.grant');
        Route::delete('/vehicles/{vehicle}/permits/{profile}', [FleetVehicleController::class, 'revokePermit'])->whereNumber('profile')->name('vehicles.permits.revoke');
        Route::post('/vehicles/{vehicle}/assign', [FleetVehicleController::class, 'assign'])->name('vehicles.assign');
        Route::post('/vehicles/{vehicle}/unassign', [FleetVehicleController::class, 'unassign'])->name('vehicles.unassign');
        Route::post('/vehicles/{vehicle}/maintenance', [FleetMaintenanceController::class, 'store'])->name('vehicles.maintenance.store');
        Route::post('/vehicles/{vehicle}/out-of-service', [FleetMaintenanceController::class, 'outOfService'])->name('vehicles.out-of-service');
        Route::post('/vehicles/{vehicle}/back-in-service', [FleetMaintenanceController::class, 'backInService'])->name('vehicles.back-in-service');
    });

    Route::get('/maintenance', [FleetMaintenanceController::class, 'index'])->name('maintenance');
    Route::get('/documents', FleetDocumentController::class)->name('documents');
    Route::get('/earnings', [FleetEarningsController::class, 'index'])->name('earnings');
    Route::get('/earnings.csv', [FleetEarningsController::class, 'csv'])->name('earnings.csv');
    Route::get('/trips', [FleetTripController::class, 'index'])->name('trips');

    Route::get('/settings', [FleetSettingsController::class, 'edit'])->name('settings');
    Route::middleware('throttle:fleet-writes')->group(function () {
        Route::patch('/settings', [FleetSettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/managers', [FleetSettingsController::class, 'addManager'])->name('settings.managers.store');
        Route::patch('/settings/managers/{member}', [FleetSettingsController::class, 'updateManager'])->whereNumber('member')->name('settings.managers.update');
        Route::delete('/settings/managers/{member}', [FleetSettingsController::class, 'revokeManager'])->whereNumber('member')->name('settings.managers.destroy');
    });

    // Platform operators: identity-review rights for a fleet owner (default off).
    Route::post('/ops/{fleet}/identity-review', [FleetOpsController::class, 'identityReview'])->middleware('operator')->name('ops.identity-review');
});
