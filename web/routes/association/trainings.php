<?php

use App\Http\Controllers\AssociationAccount\ProgramController;
use Illuminate\Support\Facades\Route;

Route::get('/association/trainings', [ProgramController::class, 'trainings'])->name('association.trainings.index');
Route::get('/association/trainings/{training}', [ProgramController::class, 'training'])
    ->whereUuid('training')->name('association.trainings.show');
