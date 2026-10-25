<?php

use App\Http\Controllers\Admin\MonitoringController as AdminMonitoringController;
use App\Http\Controllers\MonitoringWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/monitoring',
    [MonitoringWorkspaceController::class, 'index']
)->name('admin.monitoring.index');

Route::post(
    '/admin/monitoring',
    [AdminMonitoringController::class, 'store']
)->name('admin.monitoring.store');

Route::patch(
    '/admin/monitoring/{monitoringRecord}',
    [AdminMonitoringController::class, 'update']
)
    ->whereUuid('monitoringRecord')
    ->name('admin.monitoring.update');
