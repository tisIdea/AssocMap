<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'public.landing')
    ->name('home');
