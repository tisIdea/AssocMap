<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/association/dashboard', [DashboardController::class, 'index'])->name('association.dashboard');
