<?php

use App\Http\Controllers\Auth\StaffAuthController;

use App\Http\Controllers\Admin\MunicipalityController;
use App\Http\Controllers\Admin\BarangayController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AssociationController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use App\Http\Controllers\Admin\ProgramActivityController as AdminProgramActivityController;
use App\Http\Controllers\Admin\MonitoringController as AdminMonitoringController;

use App\Http\Controllers\AssociationAccount\MemberApplicationController
    as AssociationAccountMemberApplicationController;

use App\Http\Controllers\FieldOfficer\MemberApplicationController
    as FieldOfficerMemberApplicationController;

use App\Http\Controllers\FieldOfficer\ProjectController
    as FieldOfficerProjectController;

use App\Http\Controllers\FieldOfficer\TrainingController
    as FieldOfficerTrainingController;

use App\Http\Controllers\FieldOfficer\ProgramActivityController
    as FieldOfficerProgramActivityController;

use App\Http\Controllers\FieldOfficer\MonitoringController
    as FieldOfficerMonitoringController;

use Illuminate\Support\Facades\Route;

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
)->name('login.store');

Route::post(
    '/logout',
    [StaffAuthController::class, 'destroy']
)->name('logout');

Route::view('/map', 'public.gis')
    ->name('public.gis');

/*
|--------------------------------------------------------------------------
| SYSTEM ADMIN
|--------------------------------------------------------------------------
*/

Route::middleware([
    'supabase.auth',
    'role:system_admin',
])->group(function () {

    Route::get('/dashboard', function () {
        return view('dashboard.index', [
            'totalAssociations' => 0,
            'totalMembers' => 0,
            'totalProjects' => 0,
            'totalTrainings' => 0,
        ]);
    })->name('admin.dashboard');

    /*
    |--------------------------------------------------------------------------
    | AREA MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/areas',
        [MunicipalityController::class, 'index']
    )->name('admin.areas.index');

    Route::post(
        '/admin/municipalities',
        [MunicipalityController::class, 'store']
    )->name('admin.municipalities.store');

    Route::patch(
        '/admin/municipalities/{municipality}',
        [MunicipalityController::class, 'update']
    )
        ->whereUuid('municipality')
        ->name('admin.municipalities.update');

    Route::patch(
        '/admin/municipalities/{municipality}/deactivate',
        [MunicipalityController::class, 'deactivate']
    )
        ->whereUuid('municipality')
        ->name('admin.municipalities.deactivate');

    Route::post(
        '/admin/barangays',
        [BarangayController::class, 'store']
    )->name('admin.barangays.store');

    Route::patch(
        '/admin/barangays/{barangay}',
        [BarangayController::class, 'update']
    )
        ->whereUuid('barangay')
        ->name('admin.barangays.update');

    Route::patch(
        '/admin/barangays/{barangay}/deactivate',
        [BarangayController::class, 'deactivate']
    )
        ->whereUuid('barangay')
        ->name('admin.barangays.deactivate');

    Route::post(
        '/admin/areas',
        [AreaController::class, 'store']
    )->name('admin.areas.store');

    /*
    |--------------------------------------------------------------------------
    | ASSOCIATION MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/associations',
        [AssociationController::class, 'index']
    )->name('admin.associations.index');

    Route::post(
        '/admin/associations',
        [AssociationController::class, 'store']
    )->name('admin.associations.store');

    Route::patch(
        '/admin/associations/{association}',
        [AssociationController::class, 'update']
    )
        ->whereUuid('association')
        ->name('admin.associations.update');

    Route::patch(
        '/admin/associations/{association}/archive',
        [AssociationController::class, 'archive']
    )
        ->whereUuid('association')
        ->name('admin.associations.archive');

    Route::patch(
        '/admin/associations/{association}/unarchive',
        [AssociationController::class, 'unarchive']
    )
        ->whereUuid('association')
        ->name('admin.associations.unarchive');

    /*
    |--------------------------------------------------------------------------
    | MEMBER MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/members',
        [MemberController::class, 'index']
    )->name('admin.members.index');

    Route::patch(
        '/admin/members/{member}/archive',
        [MemberController::class, 'archive']
    )
        ->whereUuid('member')
        ->name('admin.members.archive');

    Route::patch(
        '/admin/members/{member}/unarchive',
        [MemberController::class, 'unarchive']
    )
        ->whereUuid('member')
        ->name('admin.members.unarchive');

    /*
    |--------------------------------------------------------------------------
    | PROJECT MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/projects',
        [AdminProjectController::class, 'index']
    )->name('admin.projects.index');

    Route::post(
        '/admin/projects',
        [AdminProjectController::class, 'store']
    )->name('admin.projects.store');

    Route::patch(
        '/admin/projects/{project}',
        [AdminProjectController::class, 'update']
    )
        ->whereUuid('project')
        ->name('admin.projects.update');

    Route::patch(
        '/admin/projects/{project}/archive',
        [AdminProjectController::class, 'archive']
    )
        ->whereUuid('project')
        ->name('admin.projects.archive');

    Route::patch(
        '/admin/projects/{project}/unarchive',
        [AdminProjectController::class, 'unarchive']
    )
        ->whereUuid('project')
        ->name('admin.projects.unarchive');

    /*
    |--------------------------------------------------------------------------
    | TRAINING MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/trainings',
        [AdminTrainingController::class, 'index']
    )->name('admin.trainings.index');

    Route::post(
        '/admin/trainings',
        [AdminTrainingController::class, 'store']
    )->name('admin.trainings.store');

    Route::patch(
        '/admin/trainings/{training}',
        [AdminTrainingController::class, 'update']
    )
        ->whereUuid('training')
        ->name('admin.trainings.update');

    Route::patch(
        '/admin/trainings/{training}/archive',
        [AdminTrainingController::class, 'archive']
    )
        ->whereUuid('training')
        ->name('admin.trainings.archive');

    Route::patch(
        '/admin/trainings/{training}/unarchive',
        [AdminTrainingController::class, 'unarchive']
    )
        ->whereUuid('training')
        ->name('admin.trainings.unarchive');

    /*
    |--------------------------------------------------------------------------
    | PROGRAM ACTIVITY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/program-activities',
        [AdminProgramActivityController::class, 'index']
    )->name('admin.program-activities.index');

    Route::post(
        '/admin/program-activities',
        [AdminProgramActivityController::class, 'store']
    )->name('admin.program-activities.store');

    Route::patch(
        '/admin/program-activities/{programActivity}',
        [AdminProgramActivityController::class, 'update']
    )
        ->whereUuid('programActivity')
        ->name('admin.program-activities.update');

    Route::patch(
        '/admin/program-activities/{programActivity}/archive',
        [AdminProgramActivityController::class, 'archive']
    )
        ->whereUuid('programActivity')
        ->name('admin.program-activities.archive');

    Route::patch(
        '/admin/program-activities/{programActivity}/unarchive',
        [AdminProgramActivityController::class, 'unarchive']
    )
        ->whereUuid('programActivity')
        ->name('admin.program-activities.unarchive');

    /*
    |--------------------------------------------------------------------------
    | MONITORING
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/monitoring',
        [AdminMonitoringController::class, 'index']
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

    /*
    |--------------------------------------------------------------------------
    | MEMBER APPLICATIONS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/field-officer/member-applications',
        [
            FieldOfficerMemberApplicationController::class,
            'index',
        ]
    )->name(
        'field-officer.member-applications.index'
    );

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

    Route::get(
        '/field-officer/projects',
        [FieldOfficerProjectController::class, 'index']
    )->name('field-officer.projects.index');

    Route::post(
        '/field-officer/projects',
        [FieldOfficerProjectController::class, 'store']
    )->name('field-officer.projects.store');

    Route::patch(
        '/field-officer/projects/{project}',
        [FieldOfficerProjectController::class, 'update']
    )
        ->whereUuid('project')
        ->name('field-officer.projects.update');

    Route::patch(
        '/field-officer/projects/{project}/archive',
        [FieldOfficerProjectController::class, 'archive']
    )
        ->whereUuid('project')
        ->name('field-officer.projects.archive');

    Route::patch(
        '/field-officer/projects/{project}/unarchive',
        [FieldOfficerProjectController::class, 'unarchive']
    )
        ->whereUuid('project')
        ->name('field-officer.projects.unarchive');

    /*
    |--------------------------------------------------------------------------
    | TRAINING MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/field-officer/trainings',
        [FieldOfficerTrainingController::class, 'index']
    )->name('field-officer.trainings.index');

    Route::post(
        '/field-officer/trainings',
        [FieldOfficerTrainingController::class, 'store']
    )->name('field-officer.trainings.store');

    Route::patch(
        '/field-officer/trainings/{training}',
        [FieldOfficerTrainingController::class, 'update']
    )
        ->whereUuid('training')
        ->name('field-officer.trainings.update');

    Route::patch(
        '/field-officer/trainings/{training}/archive',
        [FieldOfficerTrainingController::class, 'archive']
    )
        ->whereUuid('training')
        ->name('field-officer.trainings.archive');

    Route::patch(
        '/field-officer/trainings/{training}/unarchive',
        [FieldOfficerTrainingController::class, 'unarchive']
    )
        ->whereUuid('training')
        ->name('field-officer.trainings.unarchive');

    /*
    |--------------------------------------------------------------------------
    | PROGRAM ACTIVITY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/field-officer/program-activities',
        [FieldOfficerProgramActivityController::class, 'index']
    )->name('field-officer.program-activities.index');

    Route::post(
        '/field-officer/program-activities',
        [FieldOfficerProgramActivityController::class, 'store']
    )->name('field-officer.program-activities.store');

    Route::patch(
        '/field-officer/program-activities/{programActivity}',
        [FieldOfficerProgramActivityController::class, 'update']
    )
        ->whereUuid('programActivity')
        ->name('field-officer.program-activities.update');

    Route::patch(
        '/field-officer/program-activities/{programActivity}/archive',
        [FieldOfficerProgramActivityController::class, 'archive']
    )
        ->whereUuid('programActivity')
        ->name('field-officer.program-activities.archive');

    Route::patch(
        '/field-officer/program-activities/{programActivity}/unarchive',
        [FieldOfficerProgramActivityController::class, 'unarchive']
    )
        ->whereUuid('programActivity')
        ->name('field-officer.program-activities.unarchive');

    /*
    |--------------------------------------------------------------------------
    | MONITORING
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/field-officer/monitoring',
        [FieldOfficerMonitoringController::class, 'index']
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

    Route::get(
        '/association/member-applications',
        [
            AssociationAccountMemberApplicationController::class,
            'index',
        ]
    )->name(
        'association.member-applications.index'
    );

    Route::post(
        '/association/member-applications',
        [
            AssociationAccountMemberApplicationController::class,
            'store',
        ]
    )->name(
        'association.member-applications.store'
    );
});