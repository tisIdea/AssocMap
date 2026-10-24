<?php

use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\BarangayController;
use App\Http\Controllers\Admin\MunicipalityController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/areas',
    [MunicipalityController::class, 'index']
)->name('admin.areas.index');

Route::get('/admin/municipalities/{municipality}', [MunicipalityController::class, 'show'])
    ->whereUuid('municipality')->name('admin.municipalities.show');
Route::get('/admin/barangays/{barangay}', [BarangayController::class, 'show'])
    ->whereUuid('barangay')->name('admin.barangays.show');

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
