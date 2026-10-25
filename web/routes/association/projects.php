<?php

use App\Http\Controllers\AssociationAccount\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/association/projects', [ProjectController::class, 'index'])->name('association.projects.index');
Route::get('/association/projects/{project}', [ProjectController::class, 'show'])
    ->whereUuid('project')->name('association.projects.show');
