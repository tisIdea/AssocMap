<?php

use App\Http\Controllers\FieldOfficer\MonitoringController as FieldOfficerMonitoringController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/field-officer/monitoring/{monitoringRecord}/visit-reports',
    [FieldOfficerMonitoringController::class, 'indexVisitReports']
)->whereUuid('monitoringRecord')->name('field-officer.monitoring.visit-reports.index');

Route::post(
    '/field-officer/monitoring/{monitoringRecord}/visit-reports',
    [FieldOfficerMonitoringController::class, 'storeVisitReport']
)->whereUuid('monitoringRecord')->name('field-officer.monitoring.visit-reports.store');

Route::patch(
    '/field-officer/monitoring/{monitoringRecord}/visit-reports/{visitReport}',
    [FieldOfficerMonitoringController::class, 'updateVisitReport']
)->whereUuid('monitoringRecord')->whereUuid('visitReport')->scopeBindings()
    ->name('field-officer.monitoring.visit-reports.update');
