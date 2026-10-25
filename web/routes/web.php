<?php

use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\Auth\InvitationActivationController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/shared/reports.php';

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

require __DIR__.'/public/landing.php';

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
    $controller = \App\Http\Controllers\Auth\PasswordRecoveryController::class;
    Route::get('/forgot-password', [$controller, 'showRequest'])->name('password.forgot');
    Route::get('/forgot-password/email', [$controller, 'editRequest'])->block(120, 3)->name('password.request.edit');
    Route::post('/forgot-password', [$controller, 'requestLink'])->block(120, 3)->name('password.request');
    Route::post('/forgot-password/resend', [$controller, 'requestLink'])->block(120, 3)->name('password.resend');
    Route::get('/password-recovery', [$controller, 'callback'])->block(120, 3)->name('password.recovery');
    Route::post('/password-recovery/verify', [$controller, 'verify'])->block(120, 3)->name('password.verify');
    Route::get('/password-recovery/password', [$controller, 'showPassword'])->block(120, 3)->name('password.reset');
    Route::post('/password-recovery/password', [$controller, 'updatePassword'])->block(120, 3)->name('password.update');
});

Route::prefix('account-email-change')->name('account-email-change.')->middleware('throttle:20,1,account-email-change:')->group(function () {
    $controller = \App\Http\Controllers\Auth\AccountEmailChangeController::class;
    Route::get('/result', [$controller, 'result'])->block(120, 3)->name('result');
    Route::get('/{reference}/{mailbox}', [$controller, 'callback'])->where('reference', '[A-Za-z0-9]{64}')->where('mailbox', 'old|confirm')->block(120, 3)->name('callback');
    Route::post('/{reference}/old', [$controller, 'verifyOld'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('verify-old');
    Route::get('/{reference}/progress', [$controller, 'progress'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('progress');
    Route::post('/{reference}/begin', [$controller, 'begin'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('begin');
    Route::post('/{reference}/confirm', [$controller, 'confirm'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('confirm');
    Route::post('/{reference}/password', [$controller, 'complete'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('complete');
});

Route::prefix('account-reactivation')->name('account-reactivation.')->middleware('throttle:20,1,account-reactivation:')->group(function () {
    $controller = \App\Http\Controllers\Auth\AccountReactivationController::class;
    Route::get('/password', [$controller, 'password'])->block(120, 3)->name('password');
    Route::post('/password', [$controller, 'complete'])->block(120, 3)->name('complete');
    Route::get('/result', [$controller, 'result'])->block(120, 3)->name('result');
    Route::get('/{reference}', [$controller, 'callback'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('callback');
    Route::post('/{reference}/verify', [$controller, 'verify'])->where('reference', '[A-Za-z0-9]{64}')->block(120, 3)->name('verify');
});

require __DIR__.'/public/gis.php';

Route::prefix('invitation')->name('invitation.')->middleware('throttle:invitation-activation')
    ->group(function () {
        $restart = \App\Http\Controllers\Auth\InvitationRestartController::class;
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

/*
|--------------------------------------------------------------------------
| SYSTEM ADMIN
|--------------------------------------------------------------------------
*/

require __DIR__.'/admin/audit-logs.php';

Route::middleware([
    'supabase.auth',
    'role:system_admin',
])->group(function () {

    require __DIR__.'/admin/users.php';

    require __DIR__.'/admin/dashboard.php';

    /*
    |--------------------------------------------------------------------------
    | AREA MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/areas.php';

    /*
    |--------------------------------------------------------------------------
    | ASSOCIATION MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/associations.php';

    /*
    |--------------------------------------------------------------------------
    | MEMBER MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/members.php';

    /*
    |--------------------------------------------------------------------------
    | PROJECT MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/projects.php';

    /*
    |--------------------------------------------------------------------------
    | TRAINING MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/trainings.php';

    /*
    |--------------------------------------------------------------------------
    | PROGRAM ACTIVITY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/program-activities.php';

    /*
    |--------------------------------------------------------------------------
    | MONITORING
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/admin/monitoring.php';

    require __DIR__.'/admin/visit-reports.php';
});

/*
|--------------------------------------------------------------------------
| FIELD OFFICER
|--------------------------------------------------------------------------
*/

Route::middleware([
    'supabase.auth',
    'role:field_officer',
])->group(function () {

    require __DIR__.'/field-officer/dashboard.php';

    /*
    |--------------------------------------------------------------------------
    | MEMBER APPLICATIONS
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/field-officer/members.php';

    require __DIR__.'/field-officer/associations.php';

    require __DIR__.'/field-officer/member-applications.php';

    /*
    |--------------------------------------------------------------------------
    | PROJECT MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/field-officer/projects.php';

    /*
    |--------------------------------------------------------------------------
    | TRAINING MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/field-officer/trainings.php';

    /*
    |--------------------------------------------------------------------------
    | PROGRAM ACTIVITY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/field-officer/program-activities.php';

    /*
    |--------------------------------------------------------------------------
    | MONITORING
    |--------------------------------------------------------------------------
    */

    require __DIR__.'/field-officer/monitoring.php';

    require __DIR__.'/field-officer/visit-reports.php';
});

/*
|--------------------------------------------------------------------------
| ASSOCIATION ACCOUNT
|--------------------------------------------------------------------------
*/

Route::middleware([
    'supabase.auth',
    'role:association_account',
])->group(function () {

    require __DIR__.'/association/dashboard.php';

    require __DIR__.'/association/projects.php';

    require __DIR__.'/association/trainings.php';
    require __DIR__.'/association/program-activities.php';

    require __DIR__.'/association/member-applications.php';
});

require __DIR__.'/shared/gis.php';

require __DIR__.'/shared/monitoring.php';
