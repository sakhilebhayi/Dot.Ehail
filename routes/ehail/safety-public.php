<?php

// R-46 safety public routes (required from routes/ehail/public.php, outside the auth group). Routes only.
//
// /t/{token}: the public trip-share page and its poll. No session, no cookies, no CSRF token: the
// cookie and session middleware are removed, so a viewer is never tracked or given a cookie.
// Trusted-contact answers: reached from an email by someone without an account. GET shows a page
// with a button; only the POST changes anything (mail scanners prefetch GET links).

use App\Domain\Safety\Http\TripSharePageController;
use App\Domain\Safety\Http\TrustedContactController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('throttle:ehail-trip-share')
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->group(function () {
        Route::get('/t/{token}', [TripSharePageController::class, 'show'])->name('trip-share.show');
        Route::get('/t/{token}/status', [TripSharePageController::class, 'status'])->name('trip-share.status');
    });

Route::middleware('throttle:ehail-contact-links')->prefix('safety/contact')->name('safety.contacts.')->group(function () {
    Route::get('/confirm/{token}', [TrustedContactController::class, 'confirmShow'])->name('confirm.show');
    Route::post('/confirm/{token}', [TrustedContactController::class, 'confirm'])->name('confirm');
    Route::get('/decline/{token}', [TrustedContactController::class, 'optOutShow'])->name('opt-out.show');
    Route::post('/decline/{token}', [TrustedContactController::class, 'optOut'])->name('opt-out');
    Route::get('/stop/{contact}', [TrustedContactController::class, 'unsubscribeShow'])->middleware('signed')->name('unsubscribe');
    Route::post('/stop/{contact}', [TrustedContactController::class, 'unsubscribe'])->middleware('signed')->name('unsubscribe.store');
});
