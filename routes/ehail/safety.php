<?php

// R-46 safety routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// JSON APIs for the wave-2 passenger, driver and operations UIs (docs/R46_SAFETY.md). Limiters are defined in
// App\Providers\EhailSafetyServiceProvider.

use App\Domain\Safety\Http\CheckInController;
use App\Domain\Safety\Http\IncidentController;
use App\Domain\Safety\Http\OpsIncidentController;
use App\Domain\Safety\Http\OpsTrustController;
use App\Domain\Safety\Http\RatingController;
use App\Domain\Safety\Http\RideSafetyController;
use App\Domain\Safety\Http\TrustedContactController;
use App\Domain\Support\Http\OpsSupportController;
use App\Domain\Support\Http\SupportCaseController;
use App\Domain\Verification\Http\VerificationDocumentController;
use App\Domain\Verification\Http\VerificationReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('verification')->name('verification.')->group(function () {
    Route::get('/requirements', [VerificationDocumentController::class, 'requirements'])->name('requirements');
    Route::get('/status', [VerificationDocumentController::class, 'status'])->name('status');
    Route::get('/eligibility', [VerificationDocumentController::class, 'eligibility'])->name('eligibility');
    Route::get('/documents', [VerificationDocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [VerificationDocumentController::class, 'store'])->middleware('throttle:ehail-safety-uploads')->name('documents.store');
    Route::get('/documents/{document}', [VerificationDocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/file', [VerificationDocumentController::class, 'file'])->name('documents.file');
});

Route::prefix('safety')->name('safety.')->group(function () {
    Route::get('/contacts', [TrustedContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [TrustedContactController::class, 'store'])->middleware('throttle:ehail-contacts')->name('contacts.store');
    Route::post('/contacts/{contact}/resend', [TrustedContactController::class, 'resend'])->middleware('throttle:ehail-contacts')->name('contacts.resend');
    Route::delete('/contacts/{contact}', [TrustedContactController::class, 'destroy'])->name('contacts.destroy');

    Route::get('/rides/{ride}', [RideSafetyController::class, 'show'])->name('rides.show');
    Route::get('/rides/{ride}/driver-photo', [RideSafetyController::class, 'driverPhoto'])->name('rides.driver-photo');
    Route::post('/rides/{ride}/shares', [RideSafetyController::class, 'storeShare'])->middleware('throttle:ehail-safety-write')->name('rides.shares.store');
    Route::delete('/shares/{share}', [RideSafetyController::class, 'revokeShare'])->name('shares.revoke');
    Route::post('/rides/{ride}/sos', [IncidentController::class, 'sos'])->middleware('throttle:ehail-safety-sos')->name('rides.sos');
    Route::post('/rides/{ride}/report', [RideSafetyController::class, 'report'])->middleware('throttle:ehail-safety-write')->name('rides.report');
    Route::post('/rides/{ride}/rating', [RatingController::class, 'rate'])->middleware('throttle:ehail-ratings')->name('rides.rating');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::post('/incidents', [IncidentController::class, 'store'])->middleware('throttle:ehail-safety-sos')->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/incidents/{incident}/location', [IncidentController::class, 'location'])->middleware('throttle:ehail-safety-write')->name('incidents.location');
    Route::post('/incidents/{incident}/safe', [IncidentController::class, 'safe'])->name('incidents.safe');

    Route::get('/check-ins', [CheckInController::class, 'index'])->name('check-ins.index');
    Route::post('/check-ins/{checkIn}', [CheckInController::class, 'respond'])->name('check-ins.respond');

    Route::get('/ratings/received', [RatingController::class, 'received'])->name('ratings.received');
    Route::post('/ratings/{rating}/appeal', [RatingController::class, 'appeal'])->middleware('throttle:ehail-safety-write')->name('ratings.appeal');

    Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
        Route::get('/verification', [VerificationReviewController::class, 'queue'])->name('verification.queue');
        Route::get('/verification/{document}', [VerificationReviewController::class, 'show'])->name('verification.show');
        Route::post('/verification/{document}/assign', [VerificationReviewController::class, 'assign'])->name('verification.assign');
        Route::post('/verification/{document}/approve', [VerificationReviewController::class, 'approve'])->name('verification.approve');
        Route::post('/verification/{document}/reject', [VerificationReviewController::class, 'reject'])->name('verification.reject');
        Route::post('/verification/{document}/resubmission', [VerificationReviewController::class, 'requestResubmission'])->name('verification.resubmission');

        Route::get('/incidents', [OpsIncidentController::class, 'queue'])->name('incidents.queue');
        Route::get('/incidents/{incident}', [OpsIncidentController::class, 'show'])->name('incidents.show');
        Route::post('/incidents/{incident}/acknowledge', [OpsIncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('/incidents/{incident}/assign', [OpsIncidentController::class, 'assign'])->name('incidents.assign');
        Route::post('/incidents/{incident}/escalate', [OpsIncidentController::class, 'escalate'])->name('incidents.escalate');
        Route::post('/incidents/{incident}/notes', [OpsIncidentController::class, 'note'])->name('incidents.note');
        Route::post('/incidents/{incident}/resolve', [OpsIncidentController::class, 'resolve'])->name('incidents.resolve');
        Route::post('/incidents/{incident}/close', [OpsIncidentController::class, 'close'])->name('incidents.close');

        Route::get('/reports', [OpsTrustController::class, 'reports'])->name('reports.index');
        Route::post('/reports/{report}', [OpsTrustController::class, 'reviewReport'])->name('reports.review');
        Route::get('/comments', [OpsTrustController::class, 'heldComments'])->name('comments.index');
        Route::post('/comments/{rating}', [OpsTrustController::class, 'moderateComment'])->name('comments.moderate');
        Route::get('/appeals', [OpsTrustController::class, 'appeals'])->name('appeals.index');
        Route::post('/appeals/{appeal}', [OpsTrustController::class, 'decideAppeal'])->name('appeals.decide');
    });
});

Route::prefix('support')->name('support.')->group(function () {
    Route::get('/categories', [SupportCaseController::class, 'categories'])->name('categories');
    Route::get('/cases', [SupportCaseController::class, 'index'])->name('cases.index');
    Route::post('/cases', [SupportCaseController::class, 'store'])->middleware('throttle:ehail-safety-write')->name('cases.store');
    Route::get('/cases/{case}', [SupportCaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/messages', [SupportCaseController::class, 'reply'])->middleware('throttle:ehail-safety-write')->name('cases.reply');
    Route::get('/attachments/{message}', [SupportCaseController::class, 'attachment'])->name('attachments.show');

    Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
        Route::get('/cases', [OpsSupportController::class, 'queue'])->name('cases.queue');
        Route::get('/cases/{case}', [OpsSupportController::class, 'show'])->name('cases.show');
        Route::post('/cases/{case}/messages', [OpsSupportController::class, 'reply'])->name('cases.reply');
        Route::post('/cases/{case}/status', [OpsSupportController::class, 'transition'])->name('cases.status');
        Route::post('/cases/{case}/assign', [OpsSupportController::class, 'assign'])->name('cases.assign');
        Route::post('/cases/{case}/priority', [OpsSupportController::class, 'priority'])->name('cases.priority');
        Route::post('/cases/{case}/escalate', [OpsSupportController::class, 'escalate'])->name('cases.escalate');
    });
});
