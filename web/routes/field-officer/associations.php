<?php

use App\Http\Controllers\AssociationOfficerController;
use App\Http\Controllers\FieldOfficer\AssociationController;
use Illuminate\Support\Facades\Route;

Route::get('/field-officer/association-officers', [AssociationOfficerController::class, 'index'])
    ->name('field-officer.association-officers.index');
Route::get('/field-officer/associations', [AssociationController::class, 'index'])
    ->name('field-officer.associations.index');
Route::get('/field-officer/associations/{association}', [AssociationController::class, 'show'])
    ->whereUuid('association')->name('field-officer.associations.show');
Route::get('/field-officer/associations/{association}/officers', [AssociationOfficerController::class, 'show'])
    ->whereUuid('association')->name('field-officer.association-officers.show');
Route::patch('/field-officer/associations/{association}/officers', [AssociationOfficerController::class, 'update'])
    ->whereUuid('association')->name('field-officer.association-officers.update');
