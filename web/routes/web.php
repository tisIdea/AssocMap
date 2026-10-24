<?php

use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\Auth\InvitationActivationController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\FieldOfficerAssignmentController;

use App\Http\Controllers\Admin\MonitoringController as AdminMonitoringController;

use App\Http\Controllers\AssociationAccount\MemberApplicationController
    as AssociationAccountMemberApplicationController;

use App\Http\Controllers\FieldOfficer\MemberApplicationController
    as FieldOfficerMemberApplicationController;

use App\Http\Controllers\MemberApplicationPhotoController;
use App\Http\Middleware\MemberApplicationEvidenceHeaders;

use App\Http\Controllers\FieldOfficer\MonitoringController
    as FieldOfficerMonitoringController;

use Illuminate\Support\Facades\Route;

require __DIR__.'/shared/reports.php';

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

Route::view('/', 'public.landing')
    ->name('home');

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

Route::middleware([\App\Http\Middleware\GisRequestGuard::class, 'throttle:gis-read'])->group(function () {
    Route::get('/map', [\App\Http\Controllers\PublicGisController::class, 'index'])->name('public.gis');
    Route::get('/map/data', [\App\Http\Controllers\PublicGisController::class, 'data'])->name('public.gis.data');
    Route::get('/map/associations', [\App\Http\Controllers\PublicGisController::class, 'associations'])->name('public.gis.associations');
});

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

    Route::get('/admin/users', [UserManagementController::class, 'index'])->name('admin.users.index');
    Route::prefix('/admin/users/{userProfile}/email-change')->whereUuid('userProfile')->name('admin.users.email-change.')
        ->middleware('throttle:6,1,account-email-change-admin:')->group(function () {
            $controller = \App\Http\Controllers\Admin\AccountEmailChangeController::class;
            Route::get('/', [$controller, 'edit'])->name('edit');
            Route::post('/review', [$controller, 'review'])->name('review');
            Route::post('/', [$controller, 'store'])->name('store');
            Route::post('/cancel', [$controller, 'cancel'])->name('cancel');
        });
    Route::get('/admin/users/{userProfile}/reactivate', [\App\Http\Controllers\Admin\AccountReactivationController::class, 'edit'])
        ->whereUuid('userProfile')->name('admin.users.reactivation.edit');
    Route::post('/admin/users/{userProfile}/reactivate', [\App\Http\Controllers\Admin\AccountReactivationController::class, 'store'])
        ->whereUuid('userProfile')->middleware('throttle:6,1,account-reactivation-admin:')->name('admin.users.reactivation.store');
    Route::get('/admin/users/{userProfile}/associations', [FieldOfficerAssignmentController::class, 'edit'])
        ->whereUuid('userProfile')->name('admin.users.assignments.edit');
    Route::patch('/admin/users/{userProfile}/associations', [FieldOfficerAssignmentController::class, 'update'])
        ->whereUuid('userProfile')->name('admin.users.assignments.update');

    Route::get('/admin/users/{userProfile}', [UserManagementController::class, 'show'])
        ->whereUuid('userProfile')->name('admin.users.show');

    Route::patch('/admin/users/{userProfile}/revoke', [UserAccessController::class, 'revoke'])
        ->whereUuid('userProfile')->name('admin.users.revoke');

    Route::post('/admin/users/{userProfile}/resend-invitation', [\App\Http\Controllers\Admin\UserInvitationResendController::class, 'store'])
        ->whereUuid('userProfile')->middleware('throttle:user-invitations')->name('admin.users.invitation.resend');

    Route::get('/admin/user-invitations/create', [UserInvitationController::class, 'create'])
        ->name('admin.user-invitations.create');

    Route::post('/admin/user-invitations', [UserInvitationController::class, 'store'])
        ->middleware('throttle:user-invitations')->name('admin.user-invitations.store');

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

    Route::get(
        '/admin/monitoring',
        [\App\Http\Controllers\MonitoringWorkspaceController::class, 'index']
    )->name('admin.monitoring.index');

    Route::post(
        '/admin/monitoring',
        [AdminMonitoringController::class, 'store']
    )->name('admin.monitoring.store');

    Route::patch(
        '/admin/monitoring/{monitoringRecord}',
        [AdminMonitoringController::class, 'update']
    )
        ->whereUuid('monitoringRecord')
        ->name('admin.monitoring.update');

    Route::get(
        '/admin/monitoring/{monitoringRecord}/visit-reports',
        [AdminMonitoringController::class, 'indexVisitReports']
    )->whereUuid('monitoringRecord')->name('admin.monitoring.visit-reports.index');

    Route::post(
        '/admin/monitoring/{monitoringRecord}/visit-reports',
        [AdminMonitoringController::class, 'storeVisitReport']
    )->whereUuid('monitoringRecord')->name('admin.monitoring.visit-reports.store');

    Route::patch(
        '/admin/monitoring/{monitoringRecord}/visit-reports/{visitReport}',
        [AdminMonitoringController::class, 'updateVisitReport']
    )->whereUuid('monitoringRecord')->whereUuid('visitReport')->scopeBindings()
        ->name('admin.monitoring.visit-reports.update');
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

    Route::get('/field-officer/members', [\App\Http\Controllers\FieldOfficer\MemberController::class, 'index'])
        ->name('field-officer.members.index');
    Route::patch('/field-officer/members/{member}', [\App\Http\Controllers\FieldOfficer\MemberController::class, 'update'])
        ->whereUuid('member')->name('field-officer.members.update');
    Route::patch('/field-officer/members/{member}/archive', [\App\Http\Controllers\FieldOfficer\MemberController::class, 'archive'])
        ->whereUuid('member')->name('field-officer.members.archive');
    Route::patch('/field-officer/members/{member}/reactivate', [\App\Http\Controllers\FieldOfficer\MemberController::class, 'reactivate'])
        ->whereUuid('member')->name('field-officer.members.reactivate');

    require __DIR__.'/field-officer/associations.php';

    Route::get(
        '/field-officer/member-applications',
        [
            FieldOfficerMemberApplicationController::class,
            'index',
        ]
    )->middleware(MemberApplicationEvidenceHeaders::class)->name(
        'field-officer.member-applications.index'
    );

    Route::get('/field-officer/member-applications/{memberApplication}/photo', MemberApplicationPhotoController::class)
        ->whereUuid('memberApplication')
        ->middleware(MemberApplicationEvidenceHeaders::class)
        ->name('field-officer.member-applications.photo');

    Route::patch(
        '/field-officer/member-applications/{memberApplication}/approve',
        [
            FieldOfficerMemberApplicationController::class,
            'approve',
        ]
    )
        ->whereUuid('memberApplication')
        ->name(
            'field-officer.member-applications.approve'
        );

    Route::patch(
        '/field-officer/member-applications/{memberApplication}/reject',
        [
            FieldOfficerMemberApplicationController::class,
            'reject',
        ]
    )
        ->whereUuid('memberApplication')
        ->name(
            'field-officer.member-applications.reject'
        );

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

    Route::get(
        '/field-officer/monitoring',
        [\App\Http\Controllers\MonitoringWorkspaceController::class, 'index']
    )->name('field-officer.monitoring.index');

    Route::post(
        '/field-officer/monitoring',
        [FieldOfficerMonitoringController::class, 'store']
    )->name('field-officer.monitoring.store');

    Route::patch(
        '/field-officer/monitoring/{monitoringRecord}',
        [FieldOfficerMonitoringController::class, 'update']
    )
        ->whereUuid('monitoringRecord')
        ->name('field-officer.monitoring.update');

    Route::get(
        '/field-officer/monitoring/{monitoringRecord}/visit-reports',
        [FieldOfficerMonitoringController::class, 'indexVisitReports']
    )->whereUuid('monitoringRecord')->name('field-officer.monitoring.visit-reports.index');

    Route::post(
        '/field-officer/monitoring/{monitoringRecord}/visit-reports',
        [FieldOfficerMonitoringController::class, 'storeVisitReport']
    )->whereUuid('monitoringRecord')->name('field-officer.monitoring.visit-reports.store');

    Route::patch(
        '/field-officer/monitoring/{monitoringRecord}/visit-reports/{visitReport}',
        [FieldOfficerMonitoringController::class, 'updateVisitReport']
    )->whereUuid('monitoringRecord')->whereUuid('visitReport')->scopeBindings()
        ->name('field-officer.monitoring.visit-reports.update');
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

    Route::get('/association/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('association.dashboard');

    Route::get('/association/projects', [\App\Http\Controllers\AssociationAccount\ProjectController::class, 'index'])->name('association.projects.index');
    Route::get('/association/projects/{project}', [\App\Http\Controllers\AssociationAccount\ProjectController::class, 'show'])
        ->whereUuid('project')->name('association.projects.show');

    Route::get('/association/trainings', [\App\Http\Controllers\AssociationAccount\ProgramController::class, 'trainings'])->name('association.trainings.index');
    Route::get('/association/trainings/{training}', [\App\Http\Controllers\AssociationAccount\ProgramController::class, 'training'])
        ->whereUuid('training')->name('association.trainings.show');
    Route::get('/association/program-activities', [\App\Http\Controllers\AssociationAccount\ProgramController::class, 'activities'])->name('association.program-activities.index');
    Route::get('/association/program-activities/{activity}', [\App\Http\Controllers\AssociationAccount\ProgramController::class, 'activity'])
        ->whereUuid('activity')->name('association.program-activities.show');

    Route::get(
        '/association/member-applications',
        [
            AssociationAccountMemberApplicationController::class,
            'index',
        ]
    )->middleware(MemberApplicationEvidenceHeaders::class)->name(
        'association.member-applications.index'
    );

    Route::get('/association/member-applications/{memberApplication}/photo', MemberApplicationPhotoController::class)
        ->whereUuid('memberApplication')
        ->middleware(MemberApplicationEvidenceHeaders::class)
        ->name('association.member-applications.photo');

    Route::post(
        '/association/member-applications',
        [
            AssociationAccountMemberApplicationController::class,
            'store',
        ]
    )->middleware(MemberApplicationEvidenceHeaders::class)->name(
        'association.member-applications.store'
    );
});

foreach (['admin' => 'system_admin', 'field-officer' => 'field_officer', 'association' => 'association_account'] as $area => $role) {
    Route::middleware([\App\Http\Middleware\GisRequestGuard::class, 'supabase.auth', 'role:'.$role, 'throttle:gis-read'])
        ->prefix($area.'/gis')->name($area.'.gis.')->group(function () use ($role) {
            $controller = \App\Http\Controllers\GisWorkspaceController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/data', [$controller, 'data'])->name('data');
            Route::get('/parents', [$controller, 'parents'])->name('parents');
            if ($role === 'system_admin') {
                Route::get('/{location}/coordinates', [$controller, 'correction'])->whereUuid('location')->name('coordinates.edit');
                Route::patch('/{location}/coordinates', [$controller, 'correctGeometry'])->whereUuid('location')
                    ->middleware('throttle:gis-write')->name('coordinates.update');
            }
            if ($role !== 'association_account') {
                Route::get('/create', [$controller, 'create'])->name('create');
                Route::get('/{location}/edit', [$controller, 'edit'])->whereUuid('location')->name('edit');
                Route::post('/', [$controller, 'store'])->middleware('throttle:gis-write')->name('store');
                Route::patch('/{location}', [$controller, 'update'])->whereUuid('location')->middleware('throttle:gis-write')->name('update');
                Route::delete('/{location}', [$controller, 'destroy'])->whereUuid('location')->middleware('throttle:gis-write')->name('destroy');
                Route::patch('/{location}/{action}', [$controller, 'lifecycle'])->whereUuid('location')
                    ->whereIn('action', ['publish', 'unpublish', 'archive'])->middleware('throttle:gis-write')->name('lifecycle');
            }
            Route::get('/{location}', [$controller, 'show'])->whereUuid('location')->name('show');
        });
}

// Shared Monitoring pages retain the existing role-specific URL namespaces.
foreach (['admin' => 'system_admin', 'field-officer' => 'field_officer', 'association' => 'association_account'] as $area => $role) {
    Route::middleware(['supabase.auth', 'role:'.$role])->prefix($area.'/monitoring')
        ->name($area.'.monitoring.')->group(function () use ($role) {
            $controller = \App\Http\Controllers\MonitoringWorkspaceController::class;
            if ($role === 'association_account') Route::get('/', [$controller, 'index'])->name('index');
            if ($role !== 'association_account') {
                Route::get('/create', [$controller, 'createPage'])->name('create');
                Route::post('/{monitoringRecord}/daily-production', [$controller, 'initializeHarvest'])->whereUuid('monitoringRecord')->name('harvest.initialize');
                Route::post('/{monitoringRecord}/production/{productionId}/harvests', [$controller, 'saveHarvest'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.store');
                Route::get('/{monitoringRecord}/production/{productionId}/harvests/{entryId}/edit', [$controller, 'harvestPage'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.edit');
                Route::patch('/{monitoringRecord}/production/{productionId}/harvests/{entryId}', [$controller, 'saveHarvest'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.update');
                Route::patch('/{monitoringRecord}/production/{productionId}/harvests/{entryId}/void', [$controller, 'voidHarvest'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.void');
                Route::patch('/{monitoringRecord}/production/{productionId}/goal', [$controller, 'saveHarvestGoal'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.goal');
                Route::get('/{monitoringRecord}/edit', [$controller, 'editOverview'])->whereUuid('monitoringRecord')->name('overview.edit');
                Route::patch('/{monitoringRecord}/overview', [$controller, 'saveOverview'])->whereUuid('monitoringRecord')->name('overview.update');
                Route::get('/{monitoringRecord}/sections/{section}/edit', [$controller, 'editSection'])->whereUuid('monitoringRecord')->whereIn('section', ['production', 'income', 'materials'])->name('section.edit');
                Route::patch('/{monitoringRecord}/sections/{section}', [$controller, 'saveSection'])->whereUuid('monitoringRecord')->whereIn('section', ['production', 'income', 'materials'])->name('section.update');
                Route::get('/{monitoringRecord}/visits/create', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->name('visit.create');
                Route::post('/{monitoringRecord}/visits', [$controller, 'saveVisit'])->whereUuid('monitoringRecord')->name('visit.store');
                Route::get('/{monitoringRecord}/visits/{reportId}/edit', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.edit');
                Route::patch('/{monitoringRecord}/visits/{reportId}', [$controller, 'saveVisit'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.update');
            }
            Route::get('/{monitoringRecord}/workspace/{section?}', [$controller, 'workspace'])->whereUuid('monitoringRecord')->whereIn('section', ['overview', 'production', 'income', 'materials'])->name('workspace');
            Route::get('/{monitoringRecord}/production/{productionId}/harvests', [$controller, 'harvestPage'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.index');
            Route::get('/{monitoringRecord}/visits', [$controller, 'visits'])->whereUuid('monitoringRecord')->name('visits');
            Route::get('/{monitoringRecord}/visits/{reportId}', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.view');
        });
}
