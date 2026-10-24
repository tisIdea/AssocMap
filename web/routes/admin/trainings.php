<?php

use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/trainings',
    [AdminTrainingController::class, 'index']
)->name('admin.trainings.index');

Route::get('/admin/trainings/{training}/edit', [AdminTrainingController::class, 'edit'])
    ->whereUuid('training')->name('admin.trainings.edit');
Route::get('/admin/trainings/{training}', [AdminTrainingController::class, 'show'])
    ->whereUuid('training')->name('admin.trainings.show');

Route::post(
    '/admin/trainings',
    [AdminTrainingController::class, 'store']
)->name('admin.trainings.store');

Route::patch(
    '/admin/trainings/{training}',
    [AdminTrainingController::class, 'update']
)
    ->whereUuid('training')
    ->name('admin.trainings.update');

Route::patch(
    '/admin/trainings/{training}/archive',
    [AdminTrainingController::class, 'archive']
)
    ->whereUuid('training')
    ->name('admin.trainings.archive');

Route::patch(
    '/admin/trainings/{training}/unarchive',
    [AdminTrainingController::class, 'unarchive']
)
    ->whereUuid('training')
    ->name('admin.trainings.unarchive');
