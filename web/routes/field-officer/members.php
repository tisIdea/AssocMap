<?php

use App\Http\Controllers\FieldOfficer\MemberController;
use Illuminate\Support\Facades\Route;

Route::get('/field-officer/members', [MemberController::class, 'index'])
    ->name('field-officer.members.index');
Route::patch('/field-officer/members/{member}', [MemberController::class, 'update'])
    ->whereUuid('member')->name('field-officer.members.update');
Route::patch('/field-officer/members/{member}/archive', [MemberController::class, 'archive'])
    ->whereUuid('member')->name('field-officer.members.archive');
Route::patch('/field-officer/members/{member}/reactivate', [MemberController::class, 'reactivate'])
    ->whereUuid('member')->name('field-officer.members.reactivate');
