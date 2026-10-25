<?php

use App\Http\Controllers\Auth\AccountEmailChangeController;
use Illuminate\Support\Facades\Route;

Route::prefix('account-email-change')->name('account-email-change.')->middleware('throttle:20,1,account-email-change:')->group(function () {
    $controller = AccountEmailChangeController::class;
    Route::get('/result', [$controller, 'result'])->block(120, 3)->name('result');
    Route::get('/{reference}/{mailbox}', [$controller, 'callback'])->where('reference', '[A-Za-z0-9]{64}')->where('mailbox', 'old|confirm')->block(120, 3)->name('callback');
    Route::post('/{reference}/old', [$controller, 'verifyOld'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('verify-old');
    Route::get('/{reference}/progress', [$controller, 'progress'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('progress');
    Route::post('/{reference}/begin', [$controller, 'begin'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('begin');
    Route::post('/{reference}/confirm', [$controller, 'confirm'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('confirm');
    Route::post('/{reference}/password', [$controller, 'complete'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('complete');
});
