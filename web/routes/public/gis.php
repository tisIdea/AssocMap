<?php

use App\Http\Controllers\PublicGisController;
use App\Http\Middleware\GisRequestGuard;
use Illuminate\Support\Facades\Route;

Route::middleware([GisRequestGuard::class, 'throttle:gis-read'])->group(function () {
    Route::get('/map', [PublicGisController::class, 'index'])->name('public.gis');
    Route::get('/map/data', [PublicGisController::class, 'data'])->name('public.gis.data');
    Route::get('/map/associations', [PublicGisController::class, 'associations'])->name('public.gis.associations');
});
