<?php

use App\Http\Controllers\Auth\StaffAuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\MunicipalityController;

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
});