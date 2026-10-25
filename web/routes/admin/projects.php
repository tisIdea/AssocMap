<?php

use App\Http\Controllers\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\ProjectClosureController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/projects',
    [AdminProjectController::class, 'index']
)->name('admin.projects.index');

Route::get('/admin/projects/{project}', [ProjectClosureController::class, 'show'])
    ->whereUuid('project')->name('admin.projects.show');
Route::post('/admin/projects/{project}/close', [ProjectClosureController::class, 'store'])
    ->whereUuid('project')->name('admin.projects.close');
Route::post('/admin/projects/{project}/terminate', [ProjectClosureController::class, 'terminate'])
    ->whereUuid('project')->name('admin.projects.terminate');

Route::get('/admin/projects/{project}/edit', [AdminProjectController::class, 'edit'])
    ->whereUuid('project')->name('admin.projects.edit');

Route::post(
    '/admin/projects',
    [AdminProjectController::class, 'store']
)->name('admin.projects.store');

Route::patch(
    '/admin/projects/{project}',
    [AdminProjectController::class, 'update']
)
    ->whereUuid('project')
    ->name('admin.projects.update');

Route::patch(
    '/admin/projects/{project}/archive',
    [AdminProjectController::class, 'archive']
)
    ->whereUuid('project')
    ->name('admin.projects.archive');

Route::patch(
    '/admin/projects/{project}/unarchive',
    [AdminProjectController::class, 'unarchive']
)
    ->whereUuid('project')
    ->name('admin.projects.unarchive');
