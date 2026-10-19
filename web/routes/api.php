<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MeController;


Route::prefix('v1')->group(function () {
    Route::get('/association/dashboard', [\App\Http\Controllers\DashboardController::class, 'data'])
        ->middleware([\App\Http\Middleware\ApiRequestGuard::class, 'throttle:api-auth', 'supabase.api.auth', 'mobile.association', 'throttle:30,1'])
        ->name('api.association.dashboard');

    Route::middleware([\App\Http\Middleware\ApiRequestGuard::class, 'throttle:api-auth', 'supabase.api.auth', 'mobile.association',
        \App\Http\Middleware\ReportingRequestGuard::class, 'throttle:30,1,reporting-read:'])->group(function () {
        Route::get('/association/reports', [\App\Http\Controllers\ReportController::class, 'data'])->name('api.association.reports');
        Route::get('/association/reports/options', [\App\Http\Controllers\ReportController::class, 'options'])->name('api.association.reports.options');
    });
    Route::get('/me', MeController::class)
        ->middleware([\App\Http\Middleware\ApiRequestGuard::class, 'throttle:api-auth', 'supabase.api.auth']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
