<?php

use App\Http\Controllers\Admin\AccountEmailChangeController;
use App\Http\Controllers\Admin\AccountReactivationController;
use App\Http\Controllers\Admin\FieldOfficerAssignmentController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Admin\UserInvitationResendController;
use App\Http\Controllers\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/users', [UserManagementController::class, 'index'])->name('admin.users.index');
Route::prefix('/admin/users/{userProfile}/email-change')->whereUuid('userProfile')->name('admin.users.email-change.')
    ->middleware('throttle:6,1,account-email-change-admin:')->group(function () {
        $controller = AccountEmailChangeController::class;
        Route::get('/', [$controller, 'edit'])->name('edit');
        Route::post('/review', [$controller, 'review'])->name('review');
        Route::post('/', [$controller, 'store'])->name('store');
        Route::post('/cancel', [$controller, 'cancel'])->name('cancel');
    });
Route::get('/admin/users/{userProfile}/reactivate', [AccountReactivationController::class, 'edit'])
    ->whereUuid('userProfile')->name('admin.users.reactivation.edit');
Route::post('/admin/users/{userProfile}/reactivate', [AccountReactivationController::class, 'store'])
    ->whereUuid('userProfile')->middleware('throttle:6,1,account-reactivation-admin:')->name('admin.users.reactivation.store');
Route::get('/admin/users/{userProfile}/associations', [FieldOfficerAssignmentController::class, 'edit'])
    ->whereUuid('userProfile')->name('admin.users.assignments.edit');
Route::patch('/admin/users/{userProfile}/associations', [FieldOfficerAssignmentController::class, 'update'])
    ->whereUuid('userProfile')->name('admin.users.assignments.update');

Route::get('/admin/users/{userProfile}', [UserManagementController::class, 'show'])
    ->whereUuid('userProfile')->name('admin.users.show');

Route::patch('/admin/users/{userProfile}/revoke', [UserAccessController::class, 'revoke'])
    ->whereUuid('userProfile')->name('admin.users.revoke');

Route::post('/admin/users/{userProfile}/resend-invitation', [UserInvitationResendController::class, 'store'])
    ->whereUuid('userProfile')->middleware('throttle:user-invitations')->name('admin.users.invitation.resend');

Route::get('/admin/user-invitations/create', [UserInvitationController::class, 'create'])
    ->name('admin.user-invitations.create');

Route::post('/admin/user-invitations', [UserInvitationController::class, 'store'])
    ->middleware('throttle:user-invitations')->name('admin.user-invitations.store');
