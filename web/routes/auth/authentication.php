<?php

use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\StaffAuthController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'auth.login')
    ->name('login');

Route::post(
    '/login',
    [StaffAuthController::class, 'store']
)->middleware('throttle:staff-login')->name('login.store');

Route::post(
    '/logout',
    [StaffAuthController::class, 'destroy']
)->name('logout');

Route::middleware('throttle:password-recovery')->group(function () {
    $controller = PasswordRecoveryController::class;
    Route::get('/forgot-password', [$controller, 'showRequest'])->name('password.forgot');
    Route::get('/forgot-password/email', [$controller, 'editRequest'])->block(120, 3)->name('password.request.edit');
    Route::post('/forgot-password', [$controller, 'requestLink'])->block(120, 3)->name('password.request');
    Route::post('/forgot-password/resend', [$controller, 'requestLink'])->block(120, 3)->name('password.resend');
    Route::get('/password-recovery', [$controller, 'callback'])->block(120, 3)->name('password.recovery');
    Route::post('/password-recovery/verify', [$controller, 'verify'])->block(120, 3)->name('password.verify');
    Route::get('/password-recovery/password', [$controller, 'showPassword'])->block(120, 3)->name('password.reset');
    Route::post('/password-recovery/password', [$controller, 'updatePassword'])->block(120, 3)->name('password.update');
});
