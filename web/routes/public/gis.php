<?php

use App\Http\Controllers\PublicGisController;
use App\Http\Controllers\GisPhotoController;
use App\Http\Middleware\GisRequestGuard;
use Illuminate\Support\Facades\Route;

Route::middleware([GisRequestGuard::class, 'throttle:gis-read'])->group(function () {
    Route::get('/map', [PublicGisController::class, 'index'])->name('public.gis');
    Route::get('/map/data', [PublicGisController::class, 'data'])->name('public.gis.data');
    Route::get('/map/associations', [PublicGisController::class, 'associations'])->name('public.gis.associations');
    Route::get('/map/{location}/photos', [GisPhotoController::class, 'index'])->whereUuid('location')->name('public.gis.photos.index');
    Route::get('/map/{location}/photos/{photo}/{variant}', [GisPhotoController::class, 'image'])
        ->whereUuid('location')->whereUuid('photo')->whereIn('variant', ['thumbnail', 'display'])->name('public.gis.photos.image');
});
