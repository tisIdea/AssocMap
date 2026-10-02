<?php

use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\Admin\MunicipalityController;
use App\Http\Controllers\Admin\BarangayController;
use App\Http\Controllers\Admin\AreaController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AssociationController;
use App\Http\Controllers\Admin\MemberController;

Route::view('/', 'public.landing')
    ->name('home');

Route::view('/login', 'auth.login')
    ->name('login');

Route::post('/login', [StaffAuthController::class, 'store'])
    ->name('login.store');

Route::post('/logout', [StaffAuthController::class, 'destroy'])
    ->name('logout');

Route::view('/map', 'public.gis')
    ->name('public.gis');

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

    Route::get('/admin/areas', [MunicipalityController::class, 'index'])
        ->name('admin.areas.index');

    Route::post('/admin/municipalities', [MunicipalityController::class, 'store'])
        ->name('admin.municipalities.store');

    Route::patch('/admin/municipalities/{municipality}', [MunicipalityController::class, 'update'])
        ->whereUuid('municipality')
        ->name('admin.municipalities.update');

    Route::patch('/admin/municipalities/{municipality}/deactivate', [MunicipalityController::class, 'deactivate'])
        ->whereUuid('municipality')
        ->name('admin.municipalities.deactivate');

    Route::post('/admin/barangays', [BarangayController::class, 'store'])
        ->name('admin.barangays.store');

    Route::patch('/admin/barangays/{barangay}', [BarangayController::class, 'update'])
        ->whereUuid('barangay')
        ->name('admin.barangays.update');

    Route::patch('/admin/barangays/{barangay}/deactivate', [BarangayController::class, 'deactivate'])
        ->whereUuid('barangay')
        ->name('admin.barangays.deactivate');

    Route::post('/admin/areas', [AreaController::class, 'store'])
        ->name('admin.areas.store');

    Route::get('/admin/associations',[AssociationController::class, 'index'])
    ->name('admin.associations.index');

    Route::post('/admin/associations',[AssociationController::class, 'store'])
    ->name('admin.associations.store');

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

    Route::get('/admin/members',[MemberController::class, 'index'])
        ->name('admin.members.index');

Route::post(
    '/admin/members',
    [MemberController::class, 'store']
)->name('admin.members.store');

Route::patch(
    '/admin/members/{member}',
    [MemberController::class, 'update']
)
    ->whereUuid('member')
    ->name('admin.members.update');

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
});
