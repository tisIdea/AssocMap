<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/field-officer/dashboard', [DashboardController::class, 'index'])->name('field-officer.dashboard');
