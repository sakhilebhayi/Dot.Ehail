<?php

// R-46 money routes, loaded from routes/web.php. Routes only: no limiters, gates, aliases or bindings here (route:cache).
// Limiters (money-card-setup, money-pay, money-cash) and gates (money.*) live in EhailMoneyServiceProvider.

use App\Http\Controllers\Money\DriverMoneyController;
use App\Http\Controllers\Money\FleetMoneyController;
use App\Http\Controllers\Money\OpsMoneyController;
use App\Http\Controllers\Money\PayoutAccountController;
use App\Http\Controllers\Money\ReceiptController;
use App\Http\Controllers\Money\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('money')->name('money.')->group(function () {
    // Passenger wallet
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    Route::get('/payment-due', [WalletController::class, 'paymentDue'])->name('payment-due');
    Route::post('/cards', [WalletController::class, 'startCard'])->middleware('throttle:money-card-setup')->name('cards.start');
    Route::get('/cards/callback', [WalletController::class, 'cardCallback'])->name('cards.callback');
    Route::post('/methods/{method}/default', [WalletController::class, 'makeDefault'])->name('methods.default');
    Route::delete('/methods/{method}', [WalletController::class, 'destroy'])->name('methods.destroy');
    Route::put('/rides/{ride}/payment-choice', [WalletController::class, 'choose'])->name('rides.choice');
    Route::post('/rides/{ride}/pay-now', [WalletController::class, 'payNow'])->middleware('throttle:money-pay')->name('rides.pay-now');

    // Receipts
    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');

    // Driver
    Route::get('/driver/earnings', [DriverMoneyController::class, 'earnings'])->name('driver.earnings');
    Route::get('/driver/earnings.csv', [DriverMoneyController::class, 'earningsCsv'])->name('driver.earnings.csv');
    Route::get('/driver/earnings/{earning}', [DriverMoneyController::class, 'earning'])->name('driver.earning');
    Route::post('/driver/rides/{ride}/cash-received', [DriverMoneyController::class, 'cashReceived'])->middleware('throttle:money-cash')->name('driver.cash-received');
    Route::match(['get', 'put'], '/driver/settings', [DriverMoneyController::class, 'settings'])->name('driver.settings');
    Route::get('/driver/payouts', [DriverMoneyController::class, 'payouts'])->name('driver.payouts');
    Route::get('/driver/payouts/{payout}', [DriverMoneyController::class, 'payout'])->name('driver.payout');
    Route::get('/driver/incentives', [DriverMoneyController::class, 'incentives'])->name('driver.incentives');

    // Payout bank accounts (drivers and fleet owners)
    Route::get('/payout-accounts', [PayoutAccountController::class, 'index'])->name('payout-accounts.index');
    Route::post('/payout-accounts', [PayoutAccountController::class, 'store'])->middleware('throttle:money-pay')->name('payout-accounts.store');
    Route::post('/payout-accounts/{account}/verify', [PayoutAccountController::class, 'verify'])->middleware('throttle:money-pay')->name('payout-accounts.verify');

    // Fleet owner
    Route::get('/fleets/{fleet}/earnings', [FleetMoneyController::class, 'earnings'])->name('fleets.earnings');
    Route::get('/fleets/{fleet}/earnings.csv', [FleetMoneyController::class, 'earningsCsv'])->name('fleets.earnings.csv');

    // Finance operations (platform operators)
    Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
        Route::get('/payments', [OpsMoneyController::class, 'payments'])->name('payments');
        Route::get('/payments/{payment}', [OpsMoneyController::class, 'payment'])->name('payment');
        Route::post('/payments/{payment}/refunds', [OpsMoneyController::class, 'refund'])->name('refund');
        Route::get('/refunds', [OpsMoneyController::class, 'refunds'])->name('refunds');
        Route::get('/payout-batches', [OpsMoneyController::class, 'batches'])->name('batches');
        Route::post('/payout-batches', [OpsMoneyController::class, 'draftBatch'])->name('batches.draft');
        Route::get('/payout-batches/{batch}', [OpsMoneyController::class, 'batch'])->name('batch');
        Route::get('/payout-batches/{batch}/export.csv', [OpsMoneyController::class, 'exportBatch'])->name('batch.export');
        Route::post('/payout-batches/{batch}/mark-paid', [OpsMoneyController::class, 'markBatchPaid'])->name('batch.paid');
        Route::post('/payout-batches/{batch}/transfers', [OpsMoneyController::class, 'sendTransfers'])->name('batch.transfers');
        Route::post('/payouts/{payout}/fail', [OpsMoneyController::class, 'failPayout'])->name('payout.fail');
        Route::post('/payouts/{payout}/reverse', [OpsMoneyController::class, 'reversePayout'])->name('payout.reverse');
        Route::get('/reconciliation', [OpsMoneyController::class, 'reconciliation'])->name('reconciliation');
        Route::post('/reconciliation/settlement', [OpsMoneyController::class, 'settlement'])->name('reconciliation.settlement');
        Route::get('/issues', [OpsMoneyController::class, 'issues'])->name('issues');
        Route::post('/issues/{issue}/resolve', [OpsMoneyController::class, 'resolveIssue'])->name('issues.resolve');
        Route::get('/incentives', [OpsMoneyController::class, 'incentives'])->name('incentives');
        Route::post('/incentives', [OpsMoneyController::class, 'storeIncentive'])->name('incentives.store');
        Route::post('/incentives/{program}/status', [OpsMoneyController::class, 'updateIncentiveStatus'])->name('incentives.status');
        Route::post('/incentives/{program}/evaluate', [OpsMoneyController::class, 'evaluateIncentive'])->name('incentives.evaluate');
        Route::get('/ledger/trial-balance', [OpsMoneyController::class, 'trialBalance'])->name('ledger.trial-balance');
        Route::get('/ledger/export.csv', [OpsMoneyController::class, 'ledgerCsv'])->name('ledger.export');
        Route::post('/ledger/adjustments', [OpsMoneyController::class, 'adjustment'])->name('ledger.adjustment');
    });
});
