<?php

// R-46 business routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// docs/R46_BUSINESS.md. Every page resolves the signed-in person's membership and checks the role (Abilities);
// record ids in URLs are looked up inside that account only.

use App\Http\Controllers\Business\ApprovalController;
use App\Http\Controllers\Business\BookingController;
use App\Http\Controllers\Business\DepartmentController;
use App\Http\Controllers\Business\InvitationController;
use App\Http\Controllers\Business\OpsBusinessController;
use App\Http\Controllers\Business\PeopleController;
use App\Http\Controllers\Business\PolicyController;
use App\Http\Controllers\Business\PortalController;
use App\Http\Controllers\Business\ProfileApiController;
use App\Http\Controllers\Business\SettingsController;
use App\Http\Controllers\Business\StatementController;
use App\Http\Controllers\Business\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('business')->name('business.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::post('/apply', [PortalController::class, 'apply'])->name('apply');
    Route::post('/switch', [PortalController::class, 'switchAccount'])->name('switch');

    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::post('/invitations/listed/{member}', [InvitationController::class, 'acceptListed'])->whereNumber('member')->name('invitations.accept-listed');

    Route::get('/people', [PeopleController::class, 'index'])->name('people');
    Route::post('/people', [PeopleController::class, 'invite'])->name('people.invite');
    Route::patch('/people/{member}', [PeopleController::class, 'update'])->whereNumber('member')->name('people.update');
    Route::delete('/people/{member}', [PeopleController::class, 'destroy'])->whereNumber('member')->name('people.destroy');

    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');

    Route::get('/policies', [PolicyController::class, 'index'])->name('policies');
    Route::post('/policies', [PolicyController::class, 'store'])->name('policies.store');
    Route::patch('/policies/{policy}', [PolicyController::class, 'update'])->whereNumber('policy')->name('policies.update');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval')->name('approvals.approve');
    Route::post('/approvals/{approval}/decline', [ApprovalController::class, 'decline'])->whereNumber('approval')->name('approvals.decline');
    Route::post('/approvals/{approval}/withdraw', [ApprovalController::class, 'withdraw'])->whereNumber('approval')->name('approvals.withdraw');

    Route::get('/trips', [TripController::class, 'index'])->name('trips');
    Route::get('/trips.csv', [TripController::class, 'csv'])->name('trips.csv');

    Route::get('/invoices', [StatementController::class, 'index'])->name('invoices');
    Route::get('/invoices/month.csv', [StatementController::class, 'monthCsv'])->name('invoices.month-csv');
    Route::get('/statements/{statement}', [StatementController::class, 'show'])->whereNumber('statement')->name('statements.show');
    Route::get('/statements/{statement}/csv', [StatementController::class, 'csv'])->whereNumber('statement')->name('statements.csv');

    Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/settings/billing', [SettingsController::class, 'billing'])->name('settings.billing');

    Route::get('/book', [BookingController::class, 'create'])->name('book');

    // JSON for the passenger app and the booking page (docs/R46_BUSINESS.md section 5).
    Route::middleware('throttle:ehail-business-api')->name('api.')->group(function () {
        Route::get('/profiles', [ProfileApiController::class, 'index'])->name('profiles');
        Route::post('/trips/prepare', [ProfileApiController::class, 'prepare'])->name('prepare');
        Route::post('/approvals', [ProfileApiController::class, 'requestApproval'])->name('approvals.store');
    });
});

// Operators: review business applications, set credit terms, suspend, record payments received.
Route::middleware('operator')->prefix('ops/business')->name('ops.business')->group(function () {
    Route::get('/', [OpsBusinessController::class, 'index'])->name('');
    Route::get('/{account}', [OpsBusinessController::class, 'show'])->whereNumber('account')->name('.show');
    Route::post('/{account}/approve', [OpsBusinessController::class, 'approve'])->whereNumber('account')->name('.approve');
    Route::post('/{account}/decline', [OpsBusinessController::class, 'decline'])->whereNumber('account')->name('.decline');
    Route::post('/{account}/suspend', [OpsBusinessController::class, 'suspend'])->whereNumber('account')->name('.suspend');
    Route::post('/{account}/reactivate', [OpsBusinessController::class, 'reactivate'])->whereNumber('account')->name('.reactivate');
    Route::patch('/{account}/terms', [OpsBusinessController::class, 'terms'])->whereNumber('account')->name('.terms');
    Route::post('/{account}/payments', [OpsBusinessController::class, 'recordPayment'])->whereNumber('account')->name('.payments');
    Route::post('/{account}/statements', [OpsBusinessController::class, 'issueStatement'])->whereNumber('account')->name('.statements');
});
