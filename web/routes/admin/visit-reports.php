<?php

use App\Http\Controllers\Admin\MonitoringController as AdminMonitoringController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/monitoring/{monitoringRecord}/visit-reports',
    [AdminMonitoringController::class, 'indexVisitReports']
)->whereUuid('monitoringRecord')->name('admin.monitoring.visit-reports.index');

Route::post(
    '/admin/monitoring/{monitoringRecord}/visit-reports',
    [AdminMonitoringController::class, 'storeVisitReport']
)->whereUuid('monitoringRecord')->name('admin.monitoring.visit-reports.store');

Route::patch(
    '/admin/monitoring/{monitoringRecord}/visit-reports/{visitReport}',
    [AdminMonitoringController::class, 'updateVisitReport']
)->whereUuid('monitoringRecord')->whereUuid('visitReport')->scopeBindings()
    ->name('admin.monitoring.visit-reports.update');
