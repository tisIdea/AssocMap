<?php

use App\Http\Controllers\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\FieldOfficer\ProjectController as FieldOfficerProjectController;
use App\Http\Controllers\ProjectClosureController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/field-officer/projects',
    [FieldOfficerProjectController::class, 'index']
)->name('field-officer.projects.index');

Route::get('/field-officer/projects/{project}', [ProjectClosureController::class, 'show'])
    ->whereUuid('project')->name('field-officer.projects.show');
Route::post('/field-officer/projects/{project}/close', [ProjectClosureController::class, 'store'])
    ->whereUuid('project')->name('field-officer.projects.close');
Route::post('/field-officer/projects/{project}/terminate', [ProjectClosureController::class, 'terminate'])
    ->whereUuid('project')->name('field-officer.projects.terminate');

Route::get('/field-officer/projects/{project}/edit', [AdminProjectController::class, 'edit'])
    ->whereUuid('project')->name('field-officer.projects.edit');

Route::post(
    '/field-officer/projects',
    [FieldOfficerProjectController::class, 'store']
)->name('field-officer.projects.store');

Route::patch(
    '/field-officer/projects/{project}',
    [FieldOfficerProjectController::class, 'update']
)
    ->whereUuid('project')
    ->name('field-officer.projects.update');

Route::patch(
    '/field-officer/projects/{project}/archive',
    [FieldOfficerProjectController::class, 'archive']
)
    ->whereUuid('project')
    ->name('field-officer.projects.archive');

Route::patch(
    '/field-officer/projects/{project}/unarchive',
    [FieldOfficerProjectController::class, 'unarchive']
)
    ->whereUuid('project')
    ->name('field-officer.projects.unarchive');
