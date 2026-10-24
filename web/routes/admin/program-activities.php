<?php

use App\Http\Controllers\Admin\ProgramActivityController as AdminProgramActivityController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/program-activities',
    [AdminProgramActivityController::class, 'index']
)->name('admin.program-activities.index');

Route::get('/admin/program-activities/{programActivity}/edit', [AdminProgramActivityController::class, 'edit'])
    ->whereUuid('programActivity')->name('admin.program-activities.edit');
Route::get('/admin/program-activities/{programActivity}', [AdminProgramActivityController::class, 'show'])
    ->whereUuid('programActivity')->name('admin.program-activities.show');

Route::post(
    '/admin/program-activities',
    [AdminProgramActivityController::class, 'store']
)->name('admin.program-activities.store');

Route::patch(
    '/admin/program-activities/{programActivity}',
    [AdminProgramActivityController::class, 'update']
)
    ->whereUuid('programActivity')
    ->name('admin.program-activities.update');

Route::patch(
    '/admin/program-activities/{programActivity}/archive',
    [AdminProgramActivityController::class, 'archive']
)
    ->whereUuid('programActivity')
    ->name('admin.program-activities.archive');

Route::patch(
    '/admin/program-activities/{programActivity}/unarchive',
    [AdminProgramActivityController::class, 'unarchive']
)
    ->whereUuid('programActivity')
    ->name('admin.program-activities.unarchive');
