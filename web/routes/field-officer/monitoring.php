<?php

use App\Http\Controllers\FieldOfficer\MonitoringController as FieldOfficerMonitoringController;
use App\Http\Controllers\MonitoringWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/field-officer/monitoring',
    [MonitoringWorkspaceController::class, 'index']
)->name('field-officer.monitoring.index');

Route::post(
    '/field-officer/monitoring',
    [FieldOfficerMonitoringController::class, 'store']
)->name('field-officer.monitoring.store');

Route::patch(
    '/field-officer/monitoring/{monitoringRecord}',
    [FieldOfficerMonitoringController::class, 'update']
)
    ->whereUuid('monitoringRecord')
    ->name('field-officer.monitoring.update');
