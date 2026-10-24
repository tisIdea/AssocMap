<?php

use App\Http\Controllers\Admin\AssociationController;
use App\Http\Controllers\AssociationOfficerController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/associations',
    [AssociationController::class, 'index']
)->name('admin.associations.index');

Route::get('/admin/associations/{association}', [AssociationController::class, 'show'])
    ->whereUuid('association')->name('admin.associations.show');

Route::get('/admin/association-officers', [AssociationOfficerController::class, 'index'])
    ->name('admin.association-officers.index');
Route::get('/admin/associations/{association}/officers', [AssociationOfficerController::class, 'show'])
    ->whereUuid('association')->name('admin.association-officers.show');
Route::patch('/admin/associations/{association}/officers', [AssociationOfficerController::class, 'update'])
    ->whereUuid('association')->name('admin.association-officers.update');

Route::post(
    '/admin/associations',
    [AssociationController::class, 'store']
)->name('admin.associations.store');

Route::patch(
    '/admin/associations/{association}',
    [AssociationController::class, 'update']
)
    ->whereUuid('association')
    ->name('admin.associations.update');

Route::patch(
    '/admin/associations/{association}/archive',
    [AssociationController::class, 'archive']
)
    ->whereUuid('association')
    ->name('admin.associations.archive');

Route::patch(
    '/admin/associations/{association}/unarchive',
    [AssociationController::class, 'unarchive']
)
    ->whereUuid('association')
    ->name('admin.associations.unarchive');
