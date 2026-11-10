<?php

// R-46 comms routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// docs/R46_COMMS.md. JSON under /api/ehail/comms (errors render as JSON); pages inside the passenger, driver and ops shells.

use App\Http\Controllers\Ehail\Comms\CommsPagesController;
use App\Http\Controllers\Ehail\Comms\InboxController;
use App\Http\Controllers\Ehail\Comms\PreferencesController;
use App\Http\Controllers\Ehail\Comms\PushController;
use App\Http\Controllers\Ehail\Comms\SmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/ehail/comms')->name('ehail.comms.')->group(function () {
    Route::get('/inbox', [InboxController::class, 'index'])->middleware('throttle:ehail-comms-read')->name('inbox');
    Route::get('/inbox/summary', [InboxController::class, 'summary'])->middleware('throttle:ehail-comms-read')->name('inbox.summary');
    Route::post('/inbox/read-all', [InboxController::class, 'readAll'])->middleware('throttle:ehail-comms-write')->name('inbox.read-all');
    Route::post('/inbox/{notification}/read', [InboxController::class, 'read'])->middleware('throttle:ehail-comms-write')->name('inbox.read');

    Route::get('/preferences', [PreferencesController::class, 'show'])->middleware('throttle:ehail-comms-read')->name('preferences');
    Route::put('/preferences', [PreferencesController::class, 'update'])->middleware('throttle:ehail-comms-write')->name('preferences.update');

    Route::get('/push/key', [PushController::class, 'key'])->middleware('throttle:ehail-comms-read')->name('push.key');
    Route::post('/push/subscriptions', [PushController::class, 'store'])->middleware('throttle:ehail-comms-write')->name('push.subscribe');
    Route::delete('/push/subscriptions', [PushController::class, 'destroy'])->middleware('throttle:ehail-comms-write')->name('push.unsubscribe');
    Route::post('/push/status', [PushController::class, 'status'])->middleware('throttle:ehail-comms-read')->name('push.status');
    Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:ehail-comms-sms')->name('push.test');

    Route::post('/sms/number', [SmsController::class, 'start'])->middleware('throttle:ehail-comms-sms')->name('sms.start');
    Route::post('/sms/confirm', [SmsController::class, 'confirm'])->middleware('throttle:ehail-comms-write')->name('sms.confirm');
    Route::delete('/sms/number', [SmsController::class, 'destroy'])->middleware('throttle:ehail-comms-write')->name('sms.destroy');
});

Route::get('/notifications', [CommsPagesController::class, 'inboxRedirect'])->name('comms.inbox');
Route::get('/notifications/settings', [CommsPagesController::class, 'settingsRedirect'])->name('comms.settings');

foreach (['passenger' => 'ride', 'driver' => 'drive', 'ops' => 'ops'] as $commsSurface => $commsPrefix) {
    Route::prefix($commsPrefix.'/notifications')
        ->name($commsPrefix.'.notifications')
        ->middleware($commsSurface === 'ops' ? ['operator'] : [])
        ->group(function () use ($commsSurface) {
            Route::get('/', [CommsPagesController::class, 'inbox'])->defaults('surface', $commsSurface);
            Route::post('/read-all', [CommsPagesController::class, 'markAllRead'])->defaults('surface', $commsSurface)->middleware('throttle:ehail-comms-write')->name('.read-all');
            Route::post('/{notification}/read', [CommsPagesController::class, 'markRead'])->defaults('surface', $commsSurface)->middleware('throttle:ehail-comms-write')->name('.read');
            Route::get('/settings', [CommsPagesController::class, 'settings'])->defaults('surface', $commsSurface)->name('.settings');
            Route::put('/settings', [CommsPagesController::class, 'saveSettings'])->defaults('surface', $commsSurface)->middleware('throttle:ehail-comms-write')->name('.settings.save');
        });
}
