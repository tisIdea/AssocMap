<?php

use App\Http\Controllers\Auth\AccountReactivationController;
use Illuminate\Support\Facades\Route;

Route::prefix('account-reactivation')->name('account-reactivation.')->middleware('throttle:20,1,account-reactivation:')->group(function () {
    $controller = AccountReactivationController::class;
    Route::get('/password', [$controller, 'password'])->block(120, 3)->name('password');
    Route::post('/password', [$controller, 'complete'])->block(120, 3)->name('complete');
    Route::get('/result', [$controller, 'result'])->block(120, 3)->name('result');
    Route::get('/{reference}', [$controller, 'callback'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('callback');
    Route::post('/{reference}/verify', [$controller, 'verify'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('verify');
});
