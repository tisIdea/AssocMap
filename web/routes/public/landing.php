<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'public.landing')
    ->name('home');

Route::view('/privacy', 'public.privacy')->name('public.privacy');
Route::view('/terms', 'public.terms')->name('public.terms');
