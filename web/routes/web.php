<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/shared/reports.php';

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

require __DIR__.'/public/landing.php';

require __DIR__.'/auth/authentication.php';

require __DIR__.'/auth/account-email-change.php';

require __DIR__.'/auth/account-reactivation.php';

require __DIR__.'/public/gis.php';

require __DIR__.'/auth/invitations.php';

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

require __DIR__.'/shared/project-cover.php';
