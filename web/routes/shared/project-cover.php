<?php

use App\Http\Controllers\ProjectCoverController;
use Illuminate\Support\Facades\Route;

foreach (['admin' => 'system_admin', 'field-officer' => 'field_officer'] as $area => $role) {
    Route::middleware(['supabase.auth', 'role:'.$role])->prefix($area.'/projects/{project}/cover')
        ->whereUuid('project')->name($area.'.projects.cover.')->group(function () {
            Route::get('/', [ProjectCoverController::class, 'index'])->name('index');
            Route::post('/', [ProjectCoverController::class, 'store'])->middleware('throttle:30,1')->name('store');
            Route::delete('/{photoId}', [ProjectCoverController::class, 'destroy'])->whereUuid('photoId')->name('destroy');
            Route::post('/{photoId}/reconcile', [ProjectCoverController::class, 'reconcile'])->whereUuid('photoId')
                ->middleware('throttle:30,1')->name('reconcile');
            Route::get('/{photoId}/{variant}', [ProjectCoverController::class, 'image'])->whereUuid('photoId')
                ->whereIn('variant', ['thumbnail', 'display'])->name('image');
        });
}
