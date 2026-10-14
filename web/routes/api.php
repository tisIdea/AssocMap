<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MeController;


Route::prefix('v1')->group(function () {
    Route::get('/me', MeController::class)
        ->middleware([\App\Http\Middleware\ApiRequestGuard::class, 'throttle:api-auth', 'supabase.api.auth']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
