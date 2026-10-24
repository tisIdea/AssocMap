<?php

use Illuminate\Support\Facades\Route;

Route::middleware([
    \App\Http\Middleware\AuditRequestGuard::class,
    'supabase.auth',
    'role:system_admin',
    'throttle:60,1',
])->prefix('admin/audit-logs')->name('admin.audit-logs.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('index');
    Route::get('/{auditLog}', [\App\Http\Controllers\Admin\AuditLogController::class, 'show'])->whereUuid('auditLog')->name('show');
});
