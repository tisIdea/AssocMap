<?php

use App\Http\Controllers\Auth\InvitationActivationController;
use App\Http\Controllers\Auth\InvitationRestartController;
use Illuminate\Support\Facades\Route;

Route::prefix('invitation')->name('invitation.')->middleware('throttle:invitation-activation')
    ->group(function () {
        $restart = InvitationRestartController::class;
        Route::get('/restart', [$restart, 'show'])->name('restart');
        Route::post('/restart', [$restart, 'requestLink'])->middleware('throttle:password-recovery')->block(120, 3)->name('restart.store');
        Route::get('/restart/{reference}', [$restart, 'callback'])->where('reference', '[A-Za-z0-9]{64}')
            ->block(120, 3)->name('restart.callback');
        Route::post('/restart/{reference}/verify', [$restart, 'verify'])->where('reference', '[A-Za-z0-9]{64}')
            ->block(120, 3)->name('restart.verify');
        Route::get('/', [InvitationActivationController::class, 'showVerification'])->block(120, 3)->name('verify');
        Route::post('/verify', [InvitationActivationController::class, 'verify'])->block(120, 3)->name('verify.store');
        Route::get('/password', [InvitationActivationController::class, 'showPassword'])->block(120, 3)->name('password');
        Route::post('/password', [InvitationActivationController::class, 'setPassword'])->block(120, 3)->name('password.store');
        Route::get('/recover', [InvitationActivationController::class, 'showRecovery'])->block(120, 3)->name('recovery');
        Route::post('/recover', [InvitationActivationController::class, 'recover'])->block(120, 3)->name('recovery.store');
        Route::get('/result', [InvitationActivationController::class, 'result'])->block(120, 3)->name('result');
    });
