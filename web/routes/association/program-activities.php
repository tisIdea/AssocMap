<?php

use App\Http\Controllers\AssociationAccount\ProgramController;
use Illuminate\Support\Facades\Route;

Route::get('/association/program-activities', [ProgramController::class, 'activities'])->name('association.program-activities.index');
Route::get('/association/program-activities/{activity}', [ProgramController::class, 'activity'])
    ->whereUuid('activity')->name('association.program-activities.show');
