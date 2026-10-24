<?php

use App\Http\Controllers\Admin\MemberController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/admin/members',
    [MemberController::class, 'index']
)->name('admin.members.index');
