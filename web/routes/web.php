<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'public.landing')
    ->name('home');

Route::view('/login', 'auth.login')
    ->name('login');

Route::view('/map', 'public.gis')
    ->name('public.gis');

Route::get('/dashboard', function () {
    return view('dashboard.index', [
        'totalAssociations' => 0,
        'totalMembers' => 0,
        'totalProjects' => 0,
        'totalTrainings' => 0,
    ]);
});