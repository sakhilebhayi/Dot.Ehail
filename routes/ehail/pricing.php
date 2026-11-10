<?php

// R-46 pricing routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).

use App\Domain\Pricing\Http\Controllers\CategoryController;
use App\Domain\Pricing\Http\Controllers\Ops\CategoryAdminController;
use App\Domain\Pricing\Http\Controllers\Ops\CommissionPlanAdminController;
use App\Domain\Pricing\Http\Controllers\Ops\FareAdjustmentController;
use App\Domain\Pricing\Http\Controllers\Ops\PromotionAdminController;
use App\Domain\Pricing\Http\Controllers\Ops\RuleSetAdminController;
use App\Domain\Pricing\Http\Controllers\QuoteController;
use App\Domain\Pricing\Http\Controllers\RideFareController;
use Illuminate\Support\Facades\Route;

Route::prefix('pricing')->name('pricing.')->group(function () {
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('throttle:ehail-fare-quote')->name('quotes.store');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    Route::post('/categories/available', [CategoryController::class, 'available'])->middleware('throttle:ehail-fare-quote')->name('categories.available');
    Route::get('/rides/{ride}/fare', [RideFareController::class, 'show'])->name('rides.fare');
});

Route::middleware('operator')->prefix('ops/pricing')->name('pricing.ops.')->group(function () {
    Route::get('/rule-sets', [RuleSetAdminController::class, 'index'])->name('rule-sets.index');
    Route::post('/rule-sets', [RuleSetAdminController::class, 'store'])->name('rule-sets.store');
    Route::post('/rule-sets/simulate', [RuleSetAdminController::class, 'simulate'])->name('rule-sets.simulate');
    Route::get('/rule-sets/{ruleSet}', [RuleSetAdminController::class, 'show'])->name('rule-sets.show');
    Route::patch('/rule-sets/{ruleSet}', [RuleSetAdminController::class, 'update'])->name('rule-sets.update');
    Route::post('/rule-sets/{ruleSet}/versions', [RuleSetAdminController::class, 'version'])->name('rule-sets.version');
    Route::post('/rule-sets/{ruleSet}/publish', [RuleSetAdminController::class, 'publish'])->name('rule-sets.publish');
    Route::post('/rule-sets/{ruleSet}/retire', [RuleSetAdminController::class, 'retire'])->name('rule-sets.retire');

    Route::get('/commission-plans', [CommissionPlanAdminController::class, 'index'])->name('commission-plans.index');
    Route::post('/commission-plans', [CommissionPlanAdminController::class, 'store'])->name('commission-plans.store');
    Route::post('/commission-plans/{commissionPlan}/versions', [CommissionPlanAdminController::class, 'version'])->name('commission-plans.version');
    Route::post('/commission-plans/{commissionPlan}/publish', [CommissionPlanAdminController::class, 'publish'])->name('commission-plans.publish');
    Route::post('/commission-plans/{commissionPlan}/retire', [CommissionPlanAdminController::class, 'retire'])->name('commission-plans.retire');

    Route::get('/promotions', [PromotionAdminController::class, 'index'])->name('promotions.index');
    Route::post('/promotions', [PromotionAdminController::class, 'store'])->name('promotions.store');
    Route::post('/promotions/{promotion}/deactivate', [PromotionAdminController::class, 'deactivate'])->name('promotions.deactivate');

    Route::get('/categories', [CategoryAdminController::class, 'index'])->name('categories.index');
    Route::patch('/categories/{category}', [CategoryAdminController::class, 'update'])->name('categories.update');

    Route::post('/rides/{ride}/fare-adjustments', [FareAdjustmentController::class, 'store'])->name('rides.fare-adjustments.store');
});
