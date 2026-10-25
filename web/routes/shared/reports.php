<?php

use Illuminate\Support\Facades\Route;

foreach (['system_admin' => 'admin', 'field_officer' => 'field-officer', 'association_account' => 'association'] as $reportRole => $reportArea) {
    Route::middleware([\App\Http\Middleware\ReportingRequestGuard::class, 'supabase.auth', 'role:'.$reportRole, 'throttle:30,1,reporting-read:'])
        ->prefix($reportArea.'/reports')->name($reportArea.'.reports.')->group(function () {
            Route::get('/', [\App\Http\Controllers\ReportController::class, 'index'])->name('index');
            Route::get('/data', [\App\Http\Controllers\ReportController::class, 'data'])->name('data');
            Route::get('/options', [\App\Http\Controllers\ReportController::class, 'options'])->name('options');
            Route::post('/export/{format}', [\App\Http\Controllers\ReportController::class, 'export'])
                ->whereIn('format', ['pdf', 'xlsx'])->middleware('throttle:6,1,reporting-export:')->name('export');
        });
}
