<?php

use App\Http\Controllers\Admin\ProgramActivityController as AdminProgramActivityController;
use App\Http\Controllers\FieldOfficer\ProgramActivityController as FieldOfficerProgramActivityController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/field-officer/program-activities',
    [FieldOfficerProgramActivityController::class, 'index']
)->name('field-officer.program-activities.index');

Route::get('/field-officer/program-activities/{programActivity}/edit', [AdminProgramActivityController::class, 'edit'])
    ->whereUuid('programActivity')->name('field-officer.program-activities.edit');
Route::get('/field-officer/program-activities/{programActivity}', [AdminProgramActivityController::class, 'show'])
    ->whereUuid('programActivity')->name('field-officer.program-activities.show');

Route::post(
    '/field-officer/program-activities',
    [FieldOfficerProgramActivityController::class, 'store']
)->name('field-officer.program-activities.store');

Route::patch(
    '/field-officer/program-activities/{programActivity}',
    [FieldOfficerProgramActivityController::class, 'update']
)
    ->whereUuid('programActivity')
    ->name('field-officer.program-activities.update');

Route::patch(
    '/field-officer/program-activities/{programActivity}/archive',
    [FieldOfficerProgramActivityController::class, 'archive']
)
    ->whereUuid('programActivity')
    ->name('field-officer.program-activities.archive');

Route::patch(
    '/field-officer/program-activities/{programActivity}/unarchive',
    [FieldOfficerProgramActivityController::class, 'unarchive']
)
    ->whereUuid('programActivity')
    ->name('field-officer.program-activities.unarchive');
