<?php

use App\Http\Controllers\GisWorkspaceController;
use App\Http\Controllers\GisPhotoController;
use App\Http\Middleware\GisRequestGuard;
use Illuminate\Support\Facades\Route;

foreach (['admin' => 'system_admin', 'field-officer' => 'field_officer', 'association' => 'association_account'] as $area => $role) {
    Route::middleware([GisRequestGuard::class, 'supabase.auth', 'role:'.$role, 'throttle:gis-read'])
        ->prefix($area.'/gis')->name($area.'.gis.')->group(function () use ($role) {
            $controller = GisWorkspaceController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/data', [$controller, 'data'])->name('data');
            Route::get('/parents', [$controller, 'parents'])->name('parents');
            if ($role === 'system_admin') {
                Route::get('/{location}/coordinates', [$controller, 'correction'])->whereUuid('location')->name('coordinates.edit');
                Route::patch('/{location}/coordinates', [$controller, 'correctGeometry'])->whereUuid('location')
                    ->middleware('throttle:gis-write')->name('coordinates.update');
            }
            if ($role !== 'association_account') {
                Route::prefix('/{location}/photos')->whereUuid('location')->name('photos.')->group(function () {
                    Route::get('/', [GisPhotoController::class, 'index'])->name('index');
                    Route::post('/', [GisPhotoController::class, 'store'])->middleware('throttle:gis-write')->name('store');
                    Route::patch('/order', [GisPhotoController::class, 'reorder'])->middleware('throttle:gis-write')->name('order');
                    Route::patch('/{photo}/approval', [GisPhotoController::class, 'approval'])->whereUuid('photo')->middleware('throttle:gis-write')->name('approval');
                    Route::delete('/{photo}', [GisPhotoController::class, 'destroy'])->whereUuid('photo')->middleware('throttle:gis-write')->name('destroy');
                    Route::post('/{photo}/reconcile', [GisPhotoController::class, 'reconcile'])->whereUuid('photo')->middleware('throttle:gis-write')->name('reconcile');
                    Route::get('/{photo}/{variant}', [GisPhotoController::class, 'image'])->whereUuid('photo')->whereIn('variant', ['thumbnail', 'display'])->name('image');
                });
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
