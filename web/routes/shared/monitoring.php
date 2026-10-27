<?php

use App\Http\Controllers\MonitoringWorkspaceController;
use Illuminate\Support\Facades\Route;

// Shared Monitoring pages retain the existing role-specific URL namespaces.
foreach (['admin' => 'system_admin', 'field-officer' => 'field_officer', 'association' => 'association_account'] as $area => $role) {
    Route::middleware(['supabase.auth', 'role:'.$role])->prefix($area.'/monitoring')
        ->name($area.'.monitoring.')->group(function () use ($role) {
            $controller = MonitoringWorkspaceController::class;
            if ($role === 'association_account') {
                Route::get('/', [$controller, 'index'])->name('index');
            }
            if ($role !== 'association_account') {
                $photos = \App\Http\Controllers\MonitoringPhotoController::class;
                Route::prefix('/{monitoringRecord}/photos/{kind}/{parentId}')
                    ->whereUuid(['monitoringRecord', 'parentId'])->whereIn('kind', ['visits', 'materials'])
                    ->name('photos.')->group(function () use ($photos) {
                        Route::get('/', [$photos, 'index'])->name('index');
                        Route::post('/', [$photos, 'store'])->middleware('throttle:30,1')->name('store');
                        Route::delete('/{photoId}', [$photos, 'destroy'])->whereUuid('photoId')->name('destroy');
                        Route::post('/{photoId}/reconcile', [$photos, 'reconcile'])->whereUuid('photoId')
                            ->middleware('throttle:30,1')->name('reconcile');
                        Route::get('/{photoId}/{variant}', [$photos, 'image'])->whereUuid('photoId')
                            ->whereIn('variant', ['thumbnail', 'display'])->name('image');
                    });

                Route::get('/create', [$controller, 'createPage'])->name('create');
                Route::post('/{monitoringRecord}/daily-production', [$controller, 'initializeHarvest'])->whereUuid('monitoringRecord')->name('harvest.initialize');
                Route::post('/{monitoringRecord}/production/{productionId}/harvests', [$controller, 'saveHarvest'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.store');
                Route::get('/{monitoringRecord}/production/{productionId}/harvests/{entryId}/edit', [$controller, 'harvestPage'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.edit');
                Route::patch('/{monitoringRecord}/production/{productionId}/harvests/{entryId}', [$controller, 'saveHarvest'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.update');
                Route::patch('/{monitoringRecord}/production/{productionId}/harvests/{entryId}/void', [$controller, 'voidHarvest'])->whereUuid(['monitoringRecord', 'productionId', 'entryId'])->name('harvest.void');
                Route::patch('/{monitoringRecord}/production/{productionId}/goal', [$controller, 'saveHarvestGoal'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.goal');
                Route::get('/{monitoringRecord}/edit', [$controller, 'editOverview'])->whereUuid('monitoringRecord')->name('overview.edit');
                Route::patch('/{monitoringRecord}/overview', [$controller, 'saveOverview'])->whereUuid('monitoringRecord')->name('overview.update');
                Route::get('/{monitoringRecord}/sections/{section}/edit', [$controller, 'editSection'])->whereUuid('monitoringRecord')->whereIn('section', ['production', 'income', 'materials'])->name('section.edit');
                Route::patch('/{monitoringRecord}/sections/{section}', [$controller, 'saveSection'])->whereUuid('monitoringRecord')->whereIn('section', ['production', 'income', 'materials'])->name('section.update');
                Route::get('/{monitoringRecord}/visits/create', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->name('visit.create');
                Route::post('/{monitoringRecord}/visits', [$controller, 'saveVisit'])->whereUuid('monitoringRecord')->name('visit.store');
                Route::get('/{monitoringRecord}/visits/{reportId}/edit', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.edit');
                Route::patch('/{monitoringRecord}/visits/{reportId}', [$controller, 'saveVisit'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.update');
            }
            Route::get('/{monitoringRecord}/workspace/{section?}', [$controller, 'workspace'])->whereUuid('monitoringRecord')->whereIn('section', ['overview', 'production', 'income', 'materials'])->name('workspace');
            Route::get('/{monitoringRecord}/production/{productionId}/harvests', [$controller, 'harvestPage'])->whereUuid(['monitoringRecord', 'productionId'])->name('harvest.index');
            Route::get('/{monitoringRecord}/visits', [$controller, 'visits'])->whereUuid('monitoringRecord')->name('visits');
            Route::get('/{monitoringRecord}/visits/{reportId}', [$controller, 'visitPage'])->whereUuid('monitoringRecord')->whereUuid('reportId')->name('visit.view');
        });
}
