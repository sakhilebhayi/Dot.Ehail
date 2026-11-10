<?php

// R-46 ops routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// Every page sits behind the `operator` middleware, which also checks the operator's role against
// App\Domain\Ops\Access\RoutePermissions. Domain actions (dispatch, money, pricing, safety, support) are called from
// the pages through those domains' own JSON APIs; only the actions the ops domain owns are posted here.

use App\Http\Controllers\Ops\AuditController;
use App\Http\Controllers\Ops\DriverController;
use App\Http\Controllers\Ops\FinanceController;
use App\Http\Controllers\Ops\FleetController;
use App\Http\Controllers\Ops\InsightsController;
use App\Http\Controllers\Ops\LiveController;
use App\Http\Controllers\Ops\OpsHomeController;
use App\Http\Controllers\Ops\PricingController;
use App\Http\Controllers\Ops\RideController;
use App\Http\Controllers\Ops\RoleController;
use App\Http\Controllers\Ops\SafetyController;
use App\Http\Controllers\Ops\SupportController;
use App\Http\Controllers\Ops\SuspensionAppealController;
use App\Http\Controllers\Ops\VerificationController;
use App\Http\Controllers\Ops\ZoneController;
use Illuminate\Support\Facades\Route;

Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
    Route::get('/', OpsHomeController::class)->name('home');

    Route::get('/live', [LiveController::class, 'index'])->name('live');
    Route::get('/live/feed', [LiveController::class, 'feed'])->name('live.feed');

    Route::get('/rides', [RideController::class, 'index'])->name('rides');
    Route::get('/rides/{ride}', [RideController::class, 'show'])->whereNumber('ride')->name('rides.show');

    Route::get('/drivers', [DriverController::class, 'index'])->name('drivers');
    Route::get('/drivers/{driver}', [DriverController::class, 'show'])->whereNumber('driver')->name('drivers.show');
    Route::post('/drivers/{driver}/suspend', [DriverController::class, 'suspend'])->whereNumber('driver')->name('drivers.suspend');
    Route::post('/suspensions/{suspension}/lift', [DriverController::class, 'lift'])->name('drivers.lift');
    Route::post('/suspensions/{suspension}/appeal-decision', [DriverController::class, 'decideAppeal'])->name('drivers.appeal-decide');

    Route::get('/verification', [VerificationController::class, 'index'])->name('verification');
    Route::get('/verification/expiry', [VerificationController::class, 'expiry'])->name('verification.expiry');
    Route::get('/verification/documents/{document}', [VerificationController::class, 'show'])->name('verification.show');

    Route::get('/safety', [SafetyController::class, 'index'])->name('safety');
    Route::get('/safety/incidents/{incident}', [SafetyController::class, 'show'])->name('safety.show');
    Route::get('/safety/check-ins', [SafetyController::class, 'checkIns'])->name('safety.check-ins');
    Route::get('/safety/patterns', [SafetyController::class, 'patterns'])->name('safety.patterns');
    Route::get('/safety/reports', [SafetyController::class, 'reports'])->name('safety.reports');
    Route::get('/safety/access', [SafetyController::class, 'access'])->name('safety.access');

    Route::get('/support', [SupportController::class, 'index'])->name('support');
    Route::get('/support/cases/{case}', [SupportController::class, 'show'])->name('support.show');

    Route::get('/tariffs', [PricingController::class, 'index'])->name('pricing');
    Route::get('/tariffs/simulator', [PricingController::class, 'simulator'])->name('pricing.simulator');
    Route::get('/tariffs/promotions', [PricingController::class, 'promotions'])->name('pricing.promotions');
    Route::get('/tariffs/catalogue', [PricingController::class, 'catalogue'])->name('pricing.catalogue');
    Route::get('/tariffs/commission', [PricingController::class, 'commission'])->name('pricing.commission');
    Route::get('/tariffs/{ruleSet}', [PricingController::class, 'show'])->whereNumber('ruleSet')->name('pricing.show');

    Route::get('/areas', [ZoneController::class, 'index'])->name('zones');
    Route::post('/areas', [ZoneController::class, 'storeArea'])->name('zones.areas.store');
    Route::patch('/areas/{area}', [ZoneController::class, 'updateArea'])->name('zones.areas.update');
    Route::post('/areas/{area}/zones', [ZoneController::class, 'storeZone'])->name('zones.store');
    Route::patch('/zones/{zone}', [ZoneController::class, 'updateZone'])->name('zones.update');

    Route::get('/finance', [FinanceController::class, 'payments'])->name('finance');
    Route::get('/finance/payments/{payment}', [FinanceController::class, 'payment'])->name('finance.payment');
    Route::get('/finance/payouts', [FinanceController::class, 'payouts'])->name('finance.payouts');
    Route::get('/finance/payouts/{batch}', [FinanceController::class, 'batch'])->name('finance.batch');
    Route::get('/finance/reconciliation', [FinanceController::class, 'reconciliation'])->name('finance.reconciliation');
    Route::get('/finance/ledger', [FinanceController::class, 'ledger'])->name('finance.ledger');

    Route::get('/insights', [InsightsController::class, 'index'])->name('insights');
    Route::get('/insights/export.csv', [InsightsController::class, 'export'])->name('insights.export');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit');

    Route::get('/team', [RoleController::class, 'index'])->name('roles');
    Route::post('/team', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/team/{user}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/team/{user}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('/fleets', [FleetController::class, 'index'])->name('fleets');
});

// A suspended driver's own appeal (not an operator route): JSON for the driver app, one appeal per suspension.
Route::post('/drive/suspensions/{suspension}/appeal', SuspensionAppealController::class)->middleware('throttle:6,1')->name('ops.suspension-appeal.store');
