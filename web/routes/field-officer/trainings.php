<?php

use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use App\Http\Controllers\FieldOfficer\TrainingController as FieldOfficerTrainingController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/field-officer/trainings',
    [FieldOfficerTrainingController::class, 'index']
)->name('field-officer.trainings.index');

Route::get('/field-officer/trainings/{training}/edit', [AdminTrainingController::class, 'edit'])
    ->whereUuid('training')->name('field-officer.trainings.edit');
Route::get('/field-officer/trainings/{training}', [AdminTrainingController::class, 'show'])
    ->whereUuid('training')->name('field-officer.trainings.show');

Route::post(
    '/field-officer/trainings',
    [FieldOfficerTrainingController::class, 'store']
)->name('field-officer.trainings.store');

Route::patch(
    '/field-officer/trainings/{training}',
    [FieldOfficerTrainingController::class, 'update']
)
    ->whereUuid('training')
    ->name('field-officer.trainings.update');

Route::patch(
    '/field-officer/trainings/{training}/archive',
    [FieldOfficerTrainingController::class, 'archive']
)
    ->whereUuid('training')
    ->name('field-officer.trainings.archive');

Route::patch(
    '/field-officer/trainings/{training}/unarchive',
    [FieldOfficerTrainingController::class, 'unarchive']
)
    ->whereUuid('training')
    ->name('field-officer.trainings.unarchive');
